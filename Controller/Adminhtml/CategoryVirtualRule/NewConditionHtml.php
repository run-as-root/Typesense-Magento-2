<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryVirtualRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\ConditionInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;

/**
 * AJAX handler behind Magento_Rule's own rules.js "Add" dropdown (see
 * Block\Adminhtml\Category\VirtualRule::getNewChildUrl()), which fetches the rendered HTML for a
 * newly-added condition/combine row whenever the admin picks one from that dropdown.
 *
 * Not part of the original Task 14 plan — discovered missing during Task 13's live verification of
 * the rule builder UI. Mirrors
 * Magento\CatalogRule\Controller\Adminhtml\Promo\Catalog\NewConditionHtml
 * (vendor/mage-os/module-catalog-rule) line for line, swapping its
 * Magento\CatalogRule\Model\Rule "holder" instantiation for our own
 * Model\VirtualRule\Condition\ConditionsRule (Task 13's in-memory-only adapter): every condition
 * node needs *some* object attached via setRule() that exposes getForm() to render itself as HTML
 * (see ConditionsRule's docblock), and virtual category rules have no persisted "Rule" entity of
 * their own to serve that purpose.
 */
class NewConditionHtml extends Action implements HttpPostActionInterface, HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::virtual_category';

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
        $objectId = $this->getRequest()->getParam('id');
        $formNamespace = $this->getRequest()->getParam('form_namespace');
        $types = explode(
            '|',
            str_replace('-', '/', (string) $this->getRequest()->getParam('type', '')),
        );
        $objectType = $types[0];
        $responseBody = '';

        if (!class_exists($objectType) || !in_array(ConditionInterface::class, class_implements($objectType), true)) {
            $this->getResponse()->setBody($responseBody);
            return;
        }

        // Dynamic class instantiation from an admin-controlled request param — the same reason the
        // real Magento\CatalogRule\...\NewConditionHtml this mirrors uses
        // $this->_objectManager->create($objectType) rather than constructor injection: $objectType
        // isn't known until runtime, so it can't be a typed constructor dependency.
        $conditionModel = $this->_objectManager->create($objectType)
            ->setId($objectId)
            ->setType($objectType)
            ->setRule($this->conditionsRuleFactory->create())
            ->setPrefix('conditions');

        if (!empty($types[1])) {
            $conditionModel->setAttribute($types[1]);
        }

        if ($conditionModel instanceof AbstractCondition) {
            $conditionModel->setJsFormObject($this->getRequest()->getParam('form'));
            $conditionModel->setFormName($formNamespace);
            $responseBody = $conditionModel->asHtmlRecursive();
        }

        $this->getResponse()->setBody($responseBody);
    }
}
