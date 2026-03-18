<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\QueryMerchandiser;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use RunAsRoot\TypeSense\Api\QueryMerchandisingRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::query_merchandiser';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly QueryMerchandisingRepositoryInterface $repository,
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
                $title = __('Edit Query Merchandiser: %1', $entity->getQuery());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This query merchandiser no longer exists.'));
                $resultPage->getConfig()->getTitle()->prepend(__('Query Merchandiser'));

                return $resultPage;
            }
        } else {
            $title = __('New Query Merchandiser');
        }

        $resultPage->getConfig()->getTitle()->prepend(__('Query Merchandiser'));
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
