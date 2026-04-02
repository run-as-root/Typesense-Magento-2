<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;

class SearchRequestBuilder
{
    /** @var array<string, string> Ordered by priority — customer/order data first for analytics queries */
    private const ENTITY_QUERY_BY = [
        'customer' => 'email,firstname,lastname,group_name,default_shipping_country',
        'order' => 'increment_id,customer_name,customer_email,item_names,shipping_country,status',
        'product' => 'name,description,sku,short_description',
        'category' => 'name,description',
        'cms_page' => 'title,content',
        'store' => 'store_name,website_name,store_code',
        'system_config' => 'path,label,value',
    ];

    private const PER_PAGE = 5;

    /** @var array<string, bool>|null */
    private ?array $embeddingCache = null;

    public function __construct(
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly EntityIndexerPool $entityIndexerPool,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
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

            if ($this->collectionHasEmbedding($collectionName, $storeId)) {
                $queryBy = 'embedding,' . $queryBy;
            }

            $requests[] = [
                'collection' => $collectionName,
                'query_by' => $queryBy,
                'exclude_fields' => 'embedding',
                'per_page' => self::PER_PAGE,
            ];
        }

        return $requests;
    }

    private function collectionHasEmbedding(string $collectionName, int $storeId): bool
    {
        if ($this->embeddingCache === null) {
            $this->embeddingCache = [];
            try {
                $client = $this->clientFactory->create($storeId);

                $collections = $client->collections->retrieve();
                foreach ($collections as $collection) {
                    $hasEmbedding = false;
                    foreach ($collection['fields'] as $field) {
                        if ($field['name'] === 'embedding') {
                            $hasEmbedding = true;
                            break;
                        }
                    }
                    $this->embeddingCache[$collection['name']] = $hasEmbedding;
                }

                $aliases = $client->aliases->retrieve();
                foreach ($aliases['aliases'] ?? [] as $alias) {
                    $aliasName = $alias['name'];
                    $targetName = $alias['collection_name'];
                    if (isset($this->embeddingCache[$targetName])) {
                        $this->embeddingCache[$aliasName] = $this->embeddingCache[$targetName];
                    }
                }
            } catch (\Exception) {
            }
        }

        return $this->embeddingCache[$collectionName] ?? false;
    }
}
