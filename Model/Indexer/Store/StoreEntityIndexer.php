<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Store;

use Magento\Store\Api\Data\StoreInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Api\StoreSchemaProviderInterface;

class StoreEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private readonly StoreDataBuilder $dataBuilder,
        private readonly StoreSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'store';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_store';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $stores = $this->dataBuilder->getStores($entityIds, $storeId);

        /** @var StoreInterface $store */
        foreach ($stores as $store) {
            yield $this->dataBuilder->build($store, (int) $store->getId());
        }
    }
}
