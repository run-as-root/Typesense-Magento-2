<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\BoostRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\ConditionInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;

/**
 * Same as CategoryVirtualRule\NewConditionHtml (rules.js "Add" dropdown endpoint), under the boosting
 * rule ACL so admins without virtual-category access can still edit boosting rules.
 */
class NewConditionHtml extends Action implements HttpPostActionInterface, HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::boost_rules';

    public function __construct(
        Context $context,
        private readonly ConditionsRuleFactory $conditionsRuleFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * @return void
     */
    public function execute()
    {
        $types = explode('|', str_replace('-', '/', (string) $this->getRequest()->getParam('type', '')));
        $objectType = $types[0];

        if (!class_exists($objectType) || !in_array(ConditionInterface::class, class_implements($objectType), true)) {
            $this->getResponse()->setBody('');
            return;
        }

        // The class comes from the request, so it can't be a constructor dependency.
        $conditionModel = $this->_objectManager->create($objectType)
            ->setId($this->getRequest()->getParam('id'))
            ->setType($objectType)
            ->setRule($this->conditionsRuleFactory->create())
            ->setPrefix('conditions');

        if (!empty($types[1])) {
            $conditionModel->setAttribute($types[1]);
        }

        $responseBody = '';
        if ($conditionModel instanceof AbstractCondition) {
            $conditionModel->setJsFormObject($this->getRequest()->getParam('form'));
            $conditionModel->setFormName($this->getRequest()->getParam('form_namespace'));
            $responseBody = $conditionModel->asHtmlRecursive();
        }

        $this->getResponse()->setBody($responseBody);
    }
}
