<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class CategoryDataBuilder
{
    public function __construct(
        private readonly CategoryCollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
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
        // getUrl() only needs store_id; setStoreId() would also re-scope the shared category resource model.
        $category->setData('store_id', $storeId);

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
     * Load the store's active categories below its root category, optionally limited to entity IDs.
     *
     * @param int[] $entityIds
     */
    public function getCategoryCollection(array $entityIds, int $storeId): CategoryCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('is_active', 1);
        $collection->addFieldToFilter('path', ['like' => sprintf('1/%d/%%', $this->getRootCategoryId($storeId))]);
        $collection->addFieldToFilter('level', ['gteq' => 2]);

        if ($entityIds !== []) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
    }

    private function getRootCategoryId(int $storeId): int
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore($storeId);

        return (int) $store->getRootCategoryId();
    }
}
