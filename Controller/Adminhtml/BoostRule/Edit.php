<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\BoostRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::boost_rules';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly BoostRuleRepositoryInterface $repository,
    ) {
        parent::__construct($context);
    }

    public function execute(): Page|Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');
        $title = __('New Boosting Rule');

        if ($id) {
            try {
                $title = __('Edit Boosting Rule: %1', $this->repository->getById($id)->getName());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This boosting rule no longer exists.'));

                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('Boosting Rules'));
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
