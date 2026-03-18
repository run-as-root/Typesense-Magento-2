<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\LandingPage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::landing_pages';

    public function __construct(
        Context $context,
        private readonly LandingPageRepositoryInterface $repository,
        private readonly OverrideManagerInterface $overrideManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/*/index');

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Invalid landing page ID.'));

            return $redirect;
        }

        try {
            $entity = $this->repository->getById($id);
            $storeId = $entity->getStoreId();

            // Resolve Typesense collection and delete the override before deleting the DB record
            try {
                $store = $this->storeManager->getStore($storeId);
                $storeCode = $store->getCode();
                $collectionName = $this->collectionNameResolver->resolve('product', $storeCode, $storeId);
                $overrideId = sprintf('landing_%d', $id);
                $this->overrideManager->deleteOverride($collectionName, $overrideId);
            } catch (\Throwable $e) {
                // Log but do not abort — deleting the Typesense override is best-effort
                $this->logger->warning(
                    sprintf('Could not delete Typesense override for landing page %d: %s', $id, $e->getMessage()),
                    ['exception' => $e]
                );
            }

            $this->repository->delete($entity);
            $this->messageManager->addSuccessMessage(__('The landing page has been deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This landing page no longer exists.'));
        } catch (\Throwable $e) {
            $this->logger->error('LandingPage Delete error: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('An error occurred while deleting: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
