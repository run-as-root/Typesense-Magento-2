<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class SearchTypesenseTool implements ToolInterface
{
    private const COLLECTION_QUERY_BY = [
        'product' => 'name,description,sku,short_description',
        'order' => 'increment_id,customer_name,customer_email,item_names,shipping_country,status',
        'customer' => 'email,firstname,lastname,group_name,default_shipping_country',
        'category' => 'name,description',
        'cms_page' => 'title,content',
        'store' => 'store_name,website_name,store_code',
        'system_config' => 'path,label,value',
    ];

    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function getName(): string
    {
        return 'search_typesense';
    }

    public function getDescription(): string
    {
        return 'Search any indexed Typesense collection (product, order, customer, category, cms_page, store, system_config) with optional filters and sorting. Returns matching documents.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collection' => [
                    'type' => 'string',
                    'enum' => ['product', 'order', 'customer', 'category', 'cms_page', 'store', 'system_config'],
                    'description' => 'Which collection to search',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query text',
                ],
                'filter_by' => [
                    'type' => 'string',
                    'description' => 'Typesense filter syntax, e.g. "shipping_country:DE" or "status:complete"',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'description' => 'Sort field and direction, e.g. "sales_count:desc", "grand_total:desc", "created_at:desc"',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results to return (default 10)',
                ],
            ],
            'required' => ['collection', 'query'],
        ];
    }

    public function execute(array $arguments): string
    {
        $collectionType = $arguments['collection'] ?? '';
        $query = $arguments['query'] ?? '*';
        $filterBy = $arguments['filter_by'] ?? '';
        $sortBy = $arguments['sort_by'] ?? '';
        $limit = min((int) ($arguments['limit'] ?? 10), 20);

        if (!isset(self::COLLECTION_QUERY_BY[$collectionType])) {
            return json_encode(['error' => 'Unknown collection: ' . $collectionType]);
        }

        $store = $this->storeManager->getDefaultStoreView();
        $storeId = (int) $store->getId();
        $storeCode = $store->getCode();

        $collectionName = $this->collectionNameResolver->resolve($collectionType, $storeCode, $storeId);
        $queryBy = self::COLLECTION_QUERY_BY[$collectionType];

        $searchParams = [
            'q' => $query,
            'query_by' => $queryBy,
            'per_page' => $limit,
            'exclude_fields' => 'embedding',
        ];

        if ($filterBy !== '') {
            $searchParams['filter_by'] = $filterBy;
        }

        if ($sortBy !== '') {
            $searchParams['sort_by'] = $sortBy;
        }

        $client = $this->clientFactory->create($storeId);
        $result = $client->collections[$collectionName]->documents->search($searchParams);

        $documents = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $documents[] = $hit['document'];
        }

        return json_encode([
            'found' => $result['found'] ?? 0,
            'documents' => $documents,
        ]);
    }
}
