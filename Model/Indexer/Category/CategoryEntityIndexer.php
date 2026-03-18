<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Category;

use Magento\Catalog\Model\Category;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;

readonly class CategoryEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private CategoryDataBuilder $dataBuilder,
        private CategorySchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'category';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_category';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $collection = $this->dataBuilder->getCategoryCollection($entityIds, $storeId);

        /** @var Category $category */
        foreach ($collection as $category) {
            yield $this->dataBuilder->build($category, $storeId);
        }
    }
}
