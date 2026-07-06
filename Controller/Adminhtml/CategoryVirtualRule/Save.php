<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryVirtualRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product as VirtualRuleProductCondition;
use RunAsRoot\TypeSense\Model\VirtualRule\VirtualRuleCacheInvalidator;

/**
 * Persists the virtual-rule state Block\Adminhtml\Category\VirtualRule's template posts (flat
 * is_virtual/virtual_category_root_id/category_id/store_id fields plus Magento_Rule's own nested
 * rule[conditions][...] tree — see that block's docblock for why this is a real server-rendered
 * rule builder rather than a JSON-load/JSON-save AJAX grid like CategoryMerchandiser's tab).
 *
 * Responsibilities, in order:
 * 1. Turn the posted rule[conditions] tree into the same conditions_serialized JSON shape
 *    CategoryVirtualRuleResolver/ConditionsToFilterByCompiler already know how to decode, via a
 *    throwaway ConditionsRule holder (see that class's docblock — identical technique to Magento's
 *    own "new condition html" AJAX controllers).
 * 2. Reject the save if any leaf condition's attribute isn't one Typesense can actually filter on.
 *    Task 12's admin dropdown already restricts the *options offered*, but a hand-crafted POST (or
 *    a rule saved while an attribute was configured, then that attribute later dropped from
 *    "Additional Attributes") could otherwise persist a condition Typesense will never index. We
 *    reject rather than silently drop the bad condition, since silently changing what the rule
 *    matches without telling the admin is worse than refusing the save outright.
 * 3. Find-or-create the CategoryVirtualRuleInterface row and persist it with a cleared compiled
 *    filter cache, forcing a recompile.
 * 4. Clear the compiled cache for ancestor categories whose resolved filter may embed this one
 *    (bleed-up / virtual_category_root_id chains) via VirtualRuleCacheInvalidator.
 * 5. Re-sync CategoryMerchandisingSync for this category and every ancestor whose cache was
 *    cleared, so pin/hide overrides stay in lockstep with the (possibly now-different) filter.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::virtual_category';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CategoryVirtualRuleRepositoryInterface $ruleRepository,
        private readonly CategoryVirtualRuleFactory $ruleFactory,
        private readonly ConditionsRuleFactory $conditionsRuleFactory,
        private readonly VirtualRuleCacheInvalidator $cacheInvalidator,
        private readonly CategoryMerchandisingSync $merchandisingSync,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();

        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);

        if ($categoryId === 0) {
            return $resultJson->setData(['success' => false, 'message' => (string) __('category_id is required.')]);
        }

        $storeId = (int) $this->getRequest()->getParam('store_id', 0);

        // Admin scope (0) → use default store, matching CategoryMerchandiser\Save's convention.
        if ($storeId === 0) {
            $storeId = (int) $this->storeManager->getDefaultStoreView()->getId();
        }

        try {
            $conditionsArray = $this->parseConditions();
        } catch (\Throwable $e) {
            $this->logger->error(
                'CategoryVirtualRule Save: failed to parse rule conditions - ' . $e->getMessage(),
                ['exception' => $e],
            );

            return $resultJson->setData(['success' => false, 'message' => (string) __('Invalid rule conditions payload.')]);
        }

        $disallowedAttributes = $this->findDisallowedAttributes($conditionsArray, $storeId);

        if ($disallowedAttributes !== []) {
            return $resultJson->setData([
                'success' => false,
                'message' => (string) __(
                    'The following attributes are not allowed for virtual category filters: %1',
                    implode(', ', $disallowedAttributes),
                ),
            ]);
        }

        try {
            $conditionsSerialized = json_encode($conditionsArray, JSON_THROW_ON_ERROR);

            $rule = $this->ruleRepository->findByCategoryAndStore($categoryId, $storeId)
                ?? $this->ruleFactory->create();

            $rule->setCategoryId($categoryId);
            $rule->setStoreId($storeId);
            $rule->setIsVirtual((bool) $this->getRequest()->getParam('is_virtual', false));
            $rule->setConditionsSerialized($conditionsSerialized);
            $rule->setVirtualCategoryRootId($this->getVirtualCategoryRootId());
            // Force a recompile: whatever was cached for the old conditions no longer applies.
            $rule->setCompiledFilterCache(null);

            $this->ruleRepository->save($rule);

            $clearedCategoryIds = $this->cacheInvalidator->invalidate($categoryId, $storeId);

            // invalidate() only reports an id as "cleared" when it found a *previously non-null*
            // cache to null out (see its own unit tests) — since this category's cache was just
            // nulled above as part of persisting, it never shows up in $clearedCategoryIds even
            // though its Typesense override is now the one most in need of a re-sync. Ancestor ids
            // it does return are additional categories whose resolved filter embeds this one.
            $categoryIdsToSync = array_unique([$categoryId, ...$clearedCategoryIds]);

            $storeCode = $this->storeManager->getStore($storeId)->getCode();

            foreach ($categoryIdsToSync as $syncCategoryId) {
                $this->merchandisingSync->sync($syncCategoryId, $storeId, $storeCode);
            }

            return $resultJson->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CategoryVirtualRule Save error: ' . $e->getMessage(),
                ['exception' => $e],
            );

            return $resultJson->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function getVirtualCategoryRootId(): ?int
    {
        $rootId = $this->getRequest()->getParam('virtual_category_root_id');

        return ($rootId !== null && $rootId !== '') ? (int) $rootId : null;
    }

    /**
     * Builds the tree via a throwaway ConditionsRule holder — see that class's docblock for why
     * loadPost(['conditions' => ...]) expects the raw, still-flat rule[conditions] POST shape
     * rather than anything pre-processed here.
     *
     * @return array<string, mixed>
     */
    private function parseConditions(): array
    {
        $rulePost = $this->getRequest()->getParam('rule');
        $conditionsPost = (is_array($rulePost) && is_array($rulePost['conditions'] ?? null))
            ? $rulePost['conditions']
            : [];

        $conditionsRule = $this->conditionsRuleFactory->create();
        $conditionsRule->loadPost(['conditions' => $conditionsPost]);

        return $conditionsRule->getConditions()->asArray();
    }

    /**
     * Walks the decoded condition tree (same combine/leaf shape ConditionsToFilterByCompiler
     * already understands: a node with a "conditions" key is a combine/group, otherwise it's a
     * leaf) and returns every leaf attribute that isn't in the Typesense-filterable allowlist.
     *
     * @param array<string, mixed> $conditionsArray
     * @return string[]
     */
    private function findDisallowedAttributes(array $conditionsArray, int $storeId): array
    {
        $allowed = array_merge(
            VirtualRuleProductCondition::CORE_FILTERABLE_ATTRIBUTES,
            $this->config->getAdditionalAttributes($storeId),
        );

        $disallowed = [];

        foreach ($this->collectLeafAttributes($conditionsArray) as $attribute) {
            if ($attribute === '') {
                continue;
            }

            if (!in_array($attribute, $allowed, true) && !in_array($attribute, $disallowed, true)) {
                $disallowed[] = $attribute;
            }
        }

        return $disallowed;
    }

    /**
     * @param array<string, mixed> $node
     * @return string[]
     */
    private function collectLeafAttributes(array $node): array
    {
        if (array_key_exists('conditions', $node) && is_array($node['conditions'])) {
            $attributes = [];

            foreach ($node['conditions'] as $child) {
                if (is_array($child)) {
                    $attributes = array_merge($attributes, $this->collectLeafAttributes($child));
                }
            }

            return $attributes;
        }

        return [(string) ($node['attribute'] ?? '')];
    }
}
