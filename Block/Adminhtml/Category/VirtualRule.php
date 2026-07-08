<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Category;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;

/**
 * Renders Magento's own native Magento_Rule condition builder (the same widget used by Catalog
 * Price Rule / Cart Price Rule) restricted to our RunAsRoot\TypeSense\Model\VirtualRule\Condition\
 * {Combine,Product} classes from Task 12, plus the "is_virtual" toggle and "virtual category root"
 * picker for a single category+store.
 *
 * Deliberately modeled after Merchandiser.php's isEnabled()/getStoreId()/getCategoryId() shape
 * (same three methods, same request params) rather than the merchandiser's own AJAX-grid template
 * pattern: the condition tree below is Magento's real server-rendered rule builder HTML, not a
 * client-rendered grid, so there is no equivalent "load products from Typesense as JSON" step here
 * — getConditionsHtml() renders synchronously, the way Magento_CatalogRule's own Conditions tab
 * does inside Magento\Rule\Block\Conditions::render().
 *
 * Category root chooser tradeoff: Magento ships a full category tree chooser
 * (Magento\Catalog\Block\Adminhtml\Category\Widget\Chooser, wired through the
 * catalog_category_widget/chooser AJAX controller + Magento_Widget/widget/chooser JS) for exactly
 * this kind of "pick a category" field, but it's built to live inside a CMS widget-instance form's
 * own layout handles/modal wiring. Reusing it here would mean duplicating that chooser's modal
 * layout XML and JS init outside its native context for a v1 admin UI. We instead render a plain
 * numeric category-ID input (see virtual_rule.phtml) and resolve+display the matching category name
 * next to it for a sanity check, and leave swapping in the real tree chooser as a follow-up if the
 * numeric input proves too rough in practice.
 */
class VirtualRule extends Template
{
    protected $_template = 'RunAsRoot_TypeSense::category/virtual_rule.phtml';

    /**
     * Memoizes getRule()'s repository lookup: isVirtual(), getVirtualCategoryRootId(), and
     * getConditionsHtml() each call it once per template render, and there's no need to hit the
     * repository three times for what is, for any given request, an immutable answer.
     */
    private bool $ruleLoaded = false;

    private ?CategoryVirtualRuleInterface $rule = null;

    public function __construct(
        Context $context,
        private readonly TypeSenseConfigInterface $config,
        private readonly CategoryVirtualRuleRepositoryInterface $ruleRepository,
        private readonly ConditionsRuleFactory $conditionsRuleFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * The category edit page's default landing scope is "All Store Views" (no "store" request
     * param at all, i.e. admin scope 0). Every virtual rule row is persisted under a real store id
     * — Controller\Adminhtml\CategoryVirtualRule\Save falls back to the default store view whenever
     * it receives store_id=0 (mirroring CategoryMerchandiser\Load's identical fallback) — so this
     * must resolve admin scope the same way, or every method below that keys off getStoreId()
     * (getRule(), isVirtual(), getVirtualCategoryRootId(), getVirtualCategoryRootName(),
     * getConditionsHtml()) silently looks up store_id=0, finds no row, and renders the fieldset as
     * if the category were never made virtual at all — even immediately after saving it.
     */
    public function getStoreId(): int
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        if ($storeId === 0) {
            $storeId = (int) $this->storeManager->getDefaultStoreView()->getId();
        }

        return $storeId;
    }

    public function getCategoryId(): ?int
    {
        $id = $this->getRequest()->getParam('id');

        return $id !== null ? (int) $id : null;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('typesense/categoryvirtualrule/save');
    }

    /**
     * Endpoint Magento_Rule's own rules.js (VarienRulesForm) calls whenever the admin picks a new
     * attribute/combination from the "Add" dropdown. See class docblock's investigation notes.
     */
    public function getNewChildUrl(): string
    {
        return $this->getUrl(
            'typesense/categoryvirtualrule/newConditionHtml',
            ['form_namespace' => $this->getFormNamespace()],
        );
    }

    /**
     * Value each condition's data-form-part attribute carries (AbstractCondition::setFormName()),
     * matching the $formName argument Magento_CatalogRule's own addTabToForm() passes through.
     * Purely a data attribute for other admin JS to scope by; it does not affect field ids (see
     * getConditionsHtml()'s docblock on why we don't touch Data\Form's html id prefix here).
     */
    public function getFormNamespace(): string
    {
        return 'typesense_virtual_rule_form';
    }

    /**
     * DOM id of the rendered root <fieldset>, and the JS variable name rules.js's
     * `new VarienRulesForm(jsObjectName, newChildUrl)` binds itself to. Must stay in sync between
     * the root Combine's getHtmlId() and the inline boot script in the template.
     */
    public function getJsObjectName(): string
    {
        return 'typesense_virtual_rule_conditions_fieldset';
    }

    public function isVirtual(): bool
    {
        return $this->getRule()?->isVirtual() ?? false;
    }

    public function getVirtualCategoryRootId(): ?int
    {
        return $this->getRule()?->getVirtualCategoryRootId();
    }

    public function getVirtualCategoryRootName(): ?string
    {
        $rootId = $this->getVirtualCategoryRootId();

        if ($rootId === null) {
            return null;
        }

        try {
            return $this->categoryRepository->get($rootId, $this->getStoreId())->getName();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * Renders Magento's real rule builder tree HTML for the current category+store's stored
     * conditions (or an empty root Combine if none exist yet), exactly the way
     * Magento\Rule\Block\Conditions::render() renders it for Catalog Price Rule — by pulling the
     * root Combine off a "rule" holder and calling asHtmlRecursive() on it directly. We skip
     * wrapping that call in Magento\Rule\Block\Conditions/a Data\Form field entirely (see
     * ConditionsRule's docblock): that machinery exists to plug into Magento\Backend\Block\Widget\
     * Form\Generic's classic tab rendering, which this htmlContent-based UI-component fieldset
     * doesn't use.
     */
    public function getConditionsHtml(): string
    {
        $conditionsRule = $this->conditionsRuleFactory->create();
        // Deliberately not calling $conditionsRule->getForm()->setHtmlIdPrefix('rule_') here (an
        // earlier draft did, mirroring promo/catalog Conditions::addTabToForm()'s
        // $form->setHtmlIdPrefix('rule_') call too literally). That call prefixes a *different*,
        // classic-tab-only wrapper Data\Form specific to addTabToForm()'s own $form local variable
        // — not $model->getForm(), which is what every condition node's getForm() actually resolves
        // to (Magento\Rule\Model\Condition\AbstractCondition::getForm(): `$this->getRule()->getForm()`).
        // $model->getForm() (== $conditionsRule->getForm() here) is never prefixed in the real
        // flow: Magento\Rule\Model\AbstractModel::getForm() lazily creates its own fresh, unprefixed
        // Data\Form. Prefixing it here made every rendered field id (type/attribute/.../new_child)
        // gain a "rule_" prefix that the raw, string-built `<ul id="{prefix}__{id}__children">` in
        // Combine::asHtmlRecursive() never gets — breaking rules.js's addRuleNewChild() id lookup
        // for the "Add" dropdown (confirmed live: it threw "Cannot read properties of undefined
        // (reading 'insertBefore')" until this line was removed).
        $serialized = $this->getRule()?->getConditionsSerialized();
        if ($serialized !== null && $serialized !== '') {
            // AbstractModel::getConditions() only takes its "unserialize + loadArray" branch the
            // first time it runs while conditions_serialized data is present (see ConditionsRule's
            // docblock), so this must be set before the single getConditions() call below.
            $conditionsRule->setConditionsSerialized($serialized);
        }

        $conditions = $conditionsRule->getConditions();
        // loadArray() (run inside the getConditions() call above, if conditions were loaded)
        // rebuilds every child node via the condition factory but never propagates formName/
        // jsFormObject to them — mirrors Magento\CatalogRule\...\Conditions::setConditionFormName().
        $this->setConditionFormNameRecursive($conditions);

        return $conditions->asHtmlRecursive();
    }

    private function getRule(): ?CategoryVirtualRuleInterface
    {
        if ($this->ruleLoaded) {
            return $this->rule;
        }

        $this->ruleLoaded = true;
        $categoryId = $this->getCategoryId();

        if ($categoryId === null) {
            return $this->rule = null;
        }

        return $this->rule = $this->ruleRepository->findByCategoryAndStore($categoryId, $this->getStoreId());
    }

    private function setConditionFormNameRecursive(\Magento\Rule\Model\Condition\AbstractCondition $condition): void
    {
        $condition->setFormName($this->getFormNamespace());
        $condition->setJsFormObject($this->getJsObjectName());

        if ($condition->getConditions() && is_array($condition->getConditions())) {
            foreach ($condition->getConditions() as $child) {
                $this->setConditionFormNameRecursive($child);
            }
        }
    }
}
