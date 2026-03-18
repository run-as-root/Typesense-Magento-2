<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;

readonly class ProductEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private ProductDataBuilder $dataBuilder,
        private ProductSchemaProvider $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'product';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_product';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): \Generator
    {
        $collection = $this->dataBuilder->getProductCollection($entityIds, $storeId);

        /** @var Product $product */
        foreach ($collection as $product) {
            yield $this->dataBuilder->build($product, $storeId);
        }
    }
}
