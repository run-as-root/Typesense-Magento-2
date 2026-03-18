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
use RunAsRoot\TypeSense\Api\QueryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Model\Curation\QueryMerchandisingSync;
use RunAsRoot\TypeSense\Model\Merchandising\QueryMerchandisingFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::query_merchandiser';

    public function __construct(
        Context $context,
        private readonly QueryMerchandisingRepositoryInterface $repository,
        private readonly QueryMerchandisingFactory $merchandisingFactory,
        private readonly QueryMerchandisingSync $sync,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create();

        try {
            if ($id) {
                $entity = $this->repository->getById($id);
            } else {
                $entity = $this->merchandisingFactory->create();
            }

            $query       = (string) $this->getRequest()->getParam('query', '');
            $matchType   = (string) $this->getRequest()->getParam('match_type', 'exact');
            $storeId     = (int) $this->getRequest()->getParam('store_id', 0);
            $isActive    = (bool) $this->getRequest()->getParam('is_active', false);
            $includesRaw = (string) $this->getRequest()->getParam('includes', '[]');
            $excludesRaw = (string) $this->getRequest()->getParam('excludes', '[]');
            $bannerRaw   = (string) $this->getRequest()->getParam('banner_config', '{}');

            if ($query === '') {
                $this->messageManager->addErrorMessage(__('Query is required.'));

                return $id
                    ? $redirect->setPath('*/*/edit', ['id' => $id])
                    : $redirect->setPath('*/*/edit');
            }

            // Validate and normalise JSON fields
            $includes = $this->decodeJsonArray($includesRaw);
            $excludes = $this->decodeJsonArray($excludesRaw);
            $banner   = $this->decodeJsonArray($bannerRaw);

            $entity->setQuery($query);
            $entity->setMatchType($matchType);
            $entity->setStoreId($storeId);
            $entity->setIsActive($isActive);
            $entity->setIncludes($includes);
            $entity->setExcludes($excludes);
            $entity->setBannerConfig($banner);

            $saved = $this->repository->save($entity);
            $savedId = (int) $saved->getId();

            // Push override to Typesense
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $this->sync->sync($savedId, $storeId, $storeCode);

            $this->messageManager->addSuccessMessage(__('The query merchandiser has been saved.'));

            return $redirect->setPath('*/*/edit', ['id' => $savedId]);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This query merchandiser no longer exists.'));

            return $redirect->setPath('*/*/index');
        } catch (\Throwable $e) {
            $this->logger->error('QueryMerchandiser Save error: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('An error occurred while saving: %1', $e->getMessage()));

            return $id
                ? $redirect->setPath('*/*/edit', ['id' => $id])
                : $redirect->setPath('*/*/edit');
        }
    }

    /**
     * Decode a JSON string into an array. Returns empty array on failure.
     *
     * @param string $json
     * @return array<mixed>
     */
    private function decodeJsonArray(string $json): array
    {
        if ($json === '' || $json === 'null') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
