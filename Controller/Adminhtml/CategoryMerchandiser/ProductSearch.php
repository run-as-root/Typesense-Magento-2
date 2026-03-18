<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryMerchandiser;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class ProductSearch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::overrides';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();
        $query      = (string) $this->getRequest()->getParam('q', '');
        $storeId    = (int) $this->getRequest()->getParam('store_id', 0);
        $collection = (string) $this->getRequest()->getParam('collection', '');

        if ($query === '') {
            return $resultJson->setData([]);
        }

        try {
            $collectionName = $collection !== ''
                ? $collection
                : $this->resolveCollectionName($storeId);

            $client = $this->clientFactory->create($storeId ?: null);

            $searchResult = $client->collections[$collectionName]->documents->search([
                'q'        => $query,
                'query_by' => 'name,sku',
                'per_page' => 20,
            ]);

            $products = $this->mapHitsToProducts($searchResult['hits'] ?? []);

            return $resultJson->setData($products);
        } catch (\Throwable $e) {
            $this->logger->error('CategoryMerchandiser ProductSearch error: ' . $e->getMessage(), ['exception' => $e]);

            return $resultJson->setData([]);
        }
    }

    private function resolveCollectionName(int $storeId): string
    {
        try {
            $store     = $this->storeManager->getStore($storeId ?: null);
            $storeCode = $store->getCode();
            $resolvedId = (int) $store->getId();

            return $this->collectionNameResolver->resolve('product', $storeCode, $resolvedId);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not resolve product collection name: ' . $e->getMessage());

            return 'products';
        }
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     * @return array<int, array<string, string>>
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
            ];
        }

        return $products;
    }
}
