<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use RunAsRoot\TypeSense\Api\CollectionSettingsProviderInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;

class ProductEntityIndexer implements EntityIndexerInterface, CollectionSettingsProviderInterface
{
    public function __construct(
        private readonly ProductDataBuilder $dataBuilder,
        private readonly ProductSchemaProviderInterface $schemaProvider,
        private readonly CategoryPositionResolver $positionResolver,
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
        return [...$this->schemaProvider->getFields(), $this->positionResolver->getSchemaField()];
    }

    public function getCollectionSettings(): array
    {
        return $this->schemaProvider->getCollectionSettings();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $collection = $this->dataBuilder->getProductCollection($entityIds, $storeId);
        $this->dataBuilder->preloadChildren($collection, $storeId);
        $this->dataBuilder->preloadCrossSells($collection);
        $productIds = array_map('intval', array_keys($collection->getItems()));
        $positions = $this->positionResolver->load($productIds);

        /** @var Product $product */
        foreach ($collection as $product) {
            // Not yielding a product makes a partial reindex delete its stale document.
            $document = $this->dataBuilder->build($product, $storeId);
            if ($document !== null) {
                yield $document + $this->positionResolver->toFields(
                    $positions[(int) $product->getId()] ?? [],
                    $document['category_ids'] ?? [],
                );
            }
        }
    }

    public function getDocumentId(int $entityId): string
    {
        return (string) $entityId;
    }
}
