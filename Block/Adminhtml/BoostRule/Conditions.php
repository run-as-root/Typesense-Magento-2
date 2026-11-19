<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\BoostRule;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Rule\Model\Condition\AbstractCondition;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;

/**
 * Renders the Magento_Rule condition builder inside the boosting rule UI form. The inputs carry
 * data-form-part="<form namespace>", which is how the UI form collects them on submit — see
 * Block\Adminhtml\Category\VirtualRule for the rendering gotchas this mirrors.
 */
class Conditions extends Template
{
    public const FORM_NAMESPACE = 'typesense_boost_rule_form';

    protected $_template = 'RunAsRoot_TypeSense::boost_rule/conditions.phtml';

    public function __construct(
        Context $context,
        private readonly BoostRuleRepositoryInterface $repository,
        private readonly ConditionsRuleFactory $conditionsRuleFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getNewChildUrl(): string
    {
        return $this->getUrl('typesense/boostrule/newConditionHtml', ['form_namespace' => self::FORM_NAMESPACE]);
    }

    public function getJsObjectName(): string
    {
        return 'typesense_boost_rule_conditions_fieldset';
    }

    public function getConditionsHtml(): string
    {
        $conditionsRule = $this->conditionsRuleFactory->create();
        $serialized = $this->getStoredConditionsSerialized();

        // Must be set before the first getConditions() call, which is the only one that loads it.
        if ($serialized !== null && $serialized !== '') {
            $conditionsRule->setConditionsSerialized($serialized);
        }

        $conditions = $conditionsRule->getConditions();
        $this->setConditionFormNameRecursive($conditions);

        return $conditions->asHtmlRecursive();
    }

    private function getStoredConditionsSerialized(): ?string
    {
        $id = (int) $this->getRequest()->getParam('id');

        if ($id === 0) {
            return null;
        }

        try {
            return $this->repository->getById($id)->getConditionsSerialized();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * loadArray() rebuilds child nodes without the form name / JS object the root carries.
     */
    private function setConditionFormNameRecursive(AbstractCondition $condition): void
    {
        $condition->setFormName(self::FORM_NAMESPACE);
        $condition->setJsFormObject($this->getJsObjectName());

        $children = $condition->getConditions();
        if (!is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            $this->setConditionFormNameRecursive($child);
        }
    }
}
