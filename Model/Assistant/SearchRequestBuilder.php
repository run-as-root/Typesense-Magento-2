<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;

class SearchRequestBuilder
{
    /** @var array<string, string> */
    private const ENTITY_QUERY_BY = [
        'product' => 'embedding,name,description,sku,short_description',
        'category' => 'name,description',
        'cms_page' => 'title,content',
        'order' => 'embedding,increment_id,customer_name,customer_email,item_names,shipping_country,status',
        'customer' => 'embedding,email,firstname,lastname,group_name,default_shipping_country',
        'store' => 'store_name,website_name,store_code',
        'system_config' => 'path,label,value',
    ];

    public function __construct(
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly EntityIndexerPool $entityIndexerPool,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function build(string $storeCode, int $storeId): array
    {
        $requests = [];
        foreach (self::ENTITY_QUERY_BY as $entityType => $queryBy) {
            if (!$this->entityIndexerPool->hasIndexer($entityType)) {
                continue;
            }

            $collectionName = $this->collectionNameResolver->resolve($entityType, $storeCode, $storeId);
            $requests[] = [
                'collection' => $collectionName,
                'query_by' => $queryBy,
                'exclude_fields' => 'embedding',
            ];
        }

        return $requests;
    }
}
