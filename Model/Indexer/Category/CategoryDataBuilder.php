<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;

class CategoryDataBuilder
{
    public function __construct(
        private readonly CategoryCollectionFactory $collectionFactory,
    ) {
    }

    /**
     * Build a Typesense document array from a category.
     *
     * @return array<string, mixed>
     */
    public function build(Category $category, int $storeId): array
    {
        $categoryId = (int) $category->getId();

        $document = [
            'id' => (string) $categoryId,
            'category_id' => $categoryId,
            'name' => (string) $category->getName(),
            'url' => (string) $category->getUrl(),
            'path' => (string) $category->getPath(),
            'level' => (int) $category->getLevel(),
            'product_count' => (int) $category->getProductCount(),
            'is_active' => (bool) $category->getIsActive(),
        ];

        $description = $category->getData('description');
        if ($description !== null && $description !== '') {
            $document['description'] = strip_tags((string) $description);
        }

        $imageUrl = (string) $category->getImageUrl();
        if ($imageUrl !== '') {
            $document['image_url'] = $imageUrl;
        }

        return $document;
    }

    /**
     * Load a category collection filtered by entity IDs, scoped to a store.
     *
     * @param int[] $entityIds
     */
    public function getCategoryCollection(array $entityIds, int $storeId): CategoryCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('is_active', 1);
        $collection->addFieldToFilter('level', ['gteq' => 2]);

        if ($entityIds !== []) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
    }
}
