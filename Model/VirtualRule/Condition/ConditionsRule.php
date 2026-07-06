<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Rule\Model\AbstractModel;

/**
 * In-memory-only adapter that lets the admin block reuse Magento's real Magento_Rule condition
 * builder machinery without a bespoke "rule" persistence layer.
 *
 * Verified against the real mage-os/module-rule source (installed in the linked Warden Magento
 * project, not present in this standalone module's composer setup):
 * - Magento\Rule\Model\Condition\AbstractCondition::getForm() is literally
 *   `return $this->getRule()->getForm();` — every condition node in the tree (the root Combine
 *   and each leaf Product) needs *some* object attached via setRule() that exposes getForm(),
 *   purely so AbstractCondition can call $this->getForm()->addField(...) while rendering itself
 *   as HTML. Magento's own Catalog Rule / Cart Price Rule "new condition html" AJAX controllers
 *   satisfy this the exact same way we do here: by instantiating a full
 *   Magento\Rule\Model\AbstractModel subclass purely to act as that holder, never persisting it.
 * - Magento\Rule\Model\AbstractModel::_resetConditions() (called lazily the first time
 *   getConditions() runs) does exactly three things: builds the combine via the abstract
 *   getConditionsInstance() hook, then `$conditions->setRule($this)->setId('1')->setPrefix('conditions')`.
 *   Reusing that lazy-init path means we get the correct root id/prefix "for free" instead of
 *   duplicating that wiring here.
 * - AbstractModel::getConditions() also transparently lazy-loads from conditions_serialized (via
 *   the injected Json serializer's unserialize() + Combine::loadArray()) the first time it's
 *   called, provided conditions_serialized was set as data before that first call. Our own
 *   CategoryVirtualRuleResolver already stores conditions_serialized as plain JSON (not PHP
 *   serialize()); AbstractModel's default serializer is also Magento\Framework\Serialize\Serializer\Json
 *   (see its own constructor default), so the two formats already agree without any translation
 *   here — provided the JSON we store carries a "type" key per node (see loadPost() below and the
 *   companion note in Block\Adminhtml\Category\VirtualRule).
 * - AbstractModel::loadPost(array $data) expects `$data['conditions']` in the same *flat*,
 *   "--"-delimited-id shape the rule builder POSTs as `rule[conditions][ID][field]` (see
 *   AbstractCondition's field name templates, all hardcoded to the "rule" root regardless of the
 *   surrounding entity — that's a Magento-wide convention, not something specific to catalog/cart
 *   price rules). It internally reassembles that flat POST shape into the nested tree structure
 *   loadArray() expects before calling it, so callers never need to reimplement
 *   _convertFlatToRecursive() themselves.
 * - getActionsInstance() is declared abstract by the parent purely because Magento_Rule doubles as
 *   the base for rules that also carry an "Actions" tab (catalog price rules, cart price rules).
 *   Virtual category rules have no actions concept at all, so this implementation intentionally
 *   throws rather than fabricating an unused Action\Collection — nothing in the conditions-only
 *   flow this class exists for ever calls getActions()/getActionsInstance().
 */
class ConditionsRule extends AbstractModel
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        TimezoneInterface $localeDate,
        private readonly CombineFactory $combineFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?ExtensionAttributesFactory $extensionFactory = null,
        ?AttributeValueFactory $customAttributeFactory = null,
        ?Json $serializer = null,
    ) {
        parent::__construct(
            $context,
            $registry,
            $formFactory,
            $localeDate,
            $resource,
            $resourceCollection,
            $data,
            $extensionFactory,
            $customAttributeFactory,
            $serializer,
        );
    }

    /**
     * @return Combine
     */
    public function getConditionsInstance()
    {
        return $this->combineFactory->create();
    }

    /**
     * Virtual category rules have no "Actions" tab; see class docblock.
     *
     * @return never
     */
    public function getActionsInstance()
    {
        throw new \LogicException('Virtual category rules do not support actions.');
    }
}
