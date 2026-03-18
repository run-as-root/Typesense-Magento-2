<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryMerchandiser;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandisingFactory;

class Save extends Action implements HttpPostActionInterface, HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::overrides';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CategoryMerchandisingRepositoryInterface $repository,
        private readonly CategoryMerchandisingFactory $merchandisingFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CategoryMerchandisingSync $sync,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();

        // Handle GET: return existing rules for a category
        if ($this->getRequest()->isGet()) {
            return $this->handleGet($resultJson);
        }

        return $this->handlePost($resultJson);
    }

    private function handleGet(Json $resultJson): Json
    {
        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);

        if ($categoryId === 0) {
            return $resultJson->setData(['rules' => []]);
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('category_id', $categoryId)
            ->create();

        $searchResults = $this->repository->getList($searchCriteria);
        $rules = [];

        foreach ($searchResults->getItems() as $item) {
            $rules[] = [
                'product_id' => $item->getProductId(),
                'position'   => $item->getPosition(),
                'action'     => $item->getAction(),
                'name'       => '',
                'sku'        => '',
                'image_url'  => '',
            ];
        }

        return $resultJson->setData(['rules' => $rules]);
    }

    private function handlePost(Json $resultJson): Json
    {
        try {
            $body = $this->getRequest()->getContent();
            $payload = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $resultJson->setData(['success' => false, 'message' => 'Invalid JSON payload.']);
        }

        $categoryId = (int) ($payload['category_id'] ?? 0);
        $storeId    = (int) ($payload['store_id'] ?? 0);
        $rules      = $payload['rules'] ?? [];

        if ($categoryId === 0) {
            return $resultJson->setData(['success' => false, 'message' => 'category_id is required.']);
        }

        try {
            // Delete existing rules for this category + store
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('category_id', $categoryId)
                ->addFilter('store_id', $storeId)
                ->create();

            $existingResults = $this->repository->getList($searchCriteria);

            foreach ($existingResults->getItems() as $existing) {
                $this->repository->delete($existing);
            }

            // Save new rules
            foreach ($rules as $ruleData) {
                $productId = (int) ($ruleData['product_id'] ?? 0);
                $position  = (int) ($ruleData['position'] ?? 1);
                $action    = (string) ($ruleData['action'] ?? 'pin');

                if ($productId === 0) {
                    continue;
                }

                $entity = $this->merchandisingFactory->create();
                $entity->setCategoryId($categoryId);
                $entity->setStoreId($storeId);
                $entity->setProductId($productId);
                $entity->setPosition($position);
                $entity->setAction($action);

                $this->repository->save($entity);
            }

            // Trigger Typesense override sync
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $this->sync->sync($categoryId, $storeId, $storeCode);

            return $resultJson->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CategoryMerchandiser Save error: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $resultJson->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
