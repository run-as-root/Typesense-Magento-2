<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\LandingPage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::landing_pages';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly LandingPageRepositoryInterface $repository,
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $id = (int) $this->getRequest()->getParam('id');
        $resultPage = $this->resultPageFactory->create();

        if ($id) {
            try {
                $entity = $this->repository->getById($id);
                $title = __('Edit Landing Page: %1', $entity->getTitle());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This landing page no longer exists.'));
                $resultPage->getConfig()->getTitle()->prepend(__('Landing Pages'));

                return $resultPage;
            }
        } else {
            $title = __('New Landing Page');
        }

        $resultPage->getConfig()->getTitle()->prepend(__('Landing Pages'));
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
