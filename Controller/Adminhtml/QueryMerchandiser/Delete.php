<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\QueryMerchandiser;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;
use RunAsRoot\TypeSense\Api\QueryMerchandisingRepositoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::query_merchandiser';

    public function __construct(
        Context $context,
        private readonly QueryMerchandisingRepositoryInterface $repository,
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
            $this->messageManager->addErrorMessage(__('Invalid query merchandiser ID.'));

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
                $overrideId = sprintf('query_merch_%d', $id);
                $this->overrideManager->deleteOverride($collectionName, $overrideId);
            } catch (\Throwable $e) {
                // Log but do not abort — deleting the Typesense override is best-effort
                $this->logger->warning(
                    sprintf('Could not delete Typesense override for query merchandiser %d: %s', $id, $e->getMessage()),
                    ['exception' => $e]
                );
            }

            $this->repository->delete($entity);
            $this->messageManager->addSuccessMessage(__('The query merchandiser has been deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This query merchandiser no longer exists.'));
        } catch (\Throwable $e) {
            $this->logger->error('QueryMerchandiser Delete error: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('An error occurred while deleting: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
