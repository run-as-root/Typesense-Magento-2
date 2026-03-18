<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryMerchandiser;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class Load extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::overrides';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly CategoryMerchandisingRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();
        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);
        $storeId    = (int) $this->getRequest()->getParam('store_id', 0);

        if ($categoryId === 0) {
            return $resultJson->setData(['products' => [], 'orphan_rules' => []]);
        }

        try {
            $products     = $this->fetchProductsFromTypesense($categoryId, $storeId);
            $rules        = $this->loadMerchandisingRules($categoryId, $storeId);
            $mergedResult = $this->mergeProductsWithRules($products, $rules);

            return $resultJson->setData($mergedResult);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CategoryMerchandiser Load error: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $resultJson->setData(['products' => [], 'orphan_rules' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsFromTypesense(int $categoryId, int $storeId): array
    {
        $store          = $this->storeManager->getStore($storeId ?: null);
        $storeCode      = $store->getCode();
        $resolvedStoreId = (int) $store->getId();
        $collectionName = $this->collectionNameResolver->resolve('product', $storeCode, $resolvedStoreId);
        $client         = $this->clientFactory->create($storeId ?: null);

        $searchResult = $client->collections[$collectionName]->documents->search([
            'q'         => '*',
            'query_by'  => 'name',
            'filter_by' => "category_ids:={$categoryId}",
            'per_page'  => 50,
        ]);

        return $this->mapHitsToProducts($searchResult['hits'] ?? []);
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     * @return array<int, array<string, mixed>>
     */
    private function mapHitsToProducts(array $hits): array
    {
        $products = [];

        foreach ($hits as $hit) {
            $doc = $hit['document'] ?? [];

            if (empty($doc)) {
                continue;
            }

            $products[] = [
                'id'        => (string) ($doc['id'] ?? ''),
                'name'      => (string) ($doc['name'] ?? ''),
                'sku'       => (string) ($doc['sku'] ?? ''),
                'image_url' => (string) ($doc['image_url'] ?? $doc['thumbnail_url'] ?? ''),
                'action'    => null,
                'position'  => null,
            ];
        }

        return $products;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private function loadMerchandisingRules(int $categoryId, int $storeId): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('category_id', $categoryId)
            ->addFilter('store_id', $storeId)
            ->create();

        $searchResults = $this->repository->getList($searchCriteria);
        $rules         = [];

        foreach ($searchResults->getItems() as $item) {
            $rules[$item->getProductId()] = [
                'action'   => $item->getAction(),
                'position' => $item->getPosition(),
            ];
        }

        return $rules;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<int|string, array<string, mixed>> $rules
     * @return array{products: array<int, array<string, mixed>>, orphan_rules: array<int, array<string, mixed>>}
     */
    private function mergeProductsWithRules(array $products, array $rules): array
    {
        $seenProductIds = [];

        foreach ($products as &$product) {
            $productId = (int) $product['id'];

            if (isset($rules[$productId])) {
                $product['action']   = $rules[$productId]['action'];
                $product['position'] = $rules[$productId]['position'];
            }

            $seenProductIds[$productId] = true;
        }
        unset($product);

        $orphanRules = [];

        foreach ($rules as $productId => $rule) {
            if (!isset($seenProductIds[(int) $productId])) {
                $orphanRules[] = [
                    'id'       => (string) $productId,
                    'action'   => $rule['action'],
                    'position' => $rule['position'],
                ];
            }
        }

        return [
            'products'     => $products,
            'orphan_rules' => $orphanRules,
        ];
    }
}
