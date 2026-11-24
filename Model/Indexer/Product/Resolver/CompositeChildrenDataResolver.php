<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\AttributeResolverInterface;

/**
 * Searchable data of the children of composite products (grouped, configurable, bundle), so the parent document
 * matches the filters and search words of its children like Magento's and ElasticSuite's indexes do. Children
 * are the enabled products of catalog_product_relation in the store's website, whatever their visibility.
 */
class CompositeChildrenDataResolver
{
    /** @var array<int, list<array{name: string, sku: string, attributes: array<string, list<string>>}>> */
    private array $childrenByParent = [];
    private ?int $storeId = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductCollectionFactory $collectionFactory,
        private readonly AttributeResolverInterface $attributeResolver,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Loads the children of a whole indexing batch with one relation query and one collection. Drops what the
     * previous batch loaded, so a full reindex does not keep every child in memory.
     *
     * @param int[] $parentIds
     */
    public function preload(array $parentIds, int $storeId): void
    {
        $this->childrenByParent = [];
        $this->storeId = $storeId;
        $this->load($parentIds, $storeId);
    }

    /**
     * @return list<array{name: string, sku: string, attributes: array<string, list<string>>}>
     */
    public function getChildren(int $parentId, int $storeId): array
    {
        if ($this->storeId !== $storeId) {
            $this->preload([$parentId], $storeId);
        } elseif (!array_key_exists($parentId, $this->childrenByParent)) {
            $this->load([$parentId], $storeId);
        }

        return $this->childrenByParent[$parentId];
    }

    /** @param int[] $parentIds */
    private function load(array $parentIds, int $storeId): void
    {
        if ($parentIds === []) {
            return;
        }

        foreach ($parentIds as $parentId) {
            $this->childrenByParent[$parentId] = [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id', 'child_id'])
            ->where('parent_id IN (?)', $parentIds);
        $relations = $connection->fetchAll($select);
        if ($relations === []) {
            return;
        }

        $childIds = array_values(array_unique(array_map('intval', array_column($relations, 'child_id'))));
        $children = $this->loadChildren($childIds, $storeId);
        foreach ($relations as $relation) {
            $child = $children[(int) $relation['child_id']] ?? null;
            if ($child !== null) {
                $this->childrenByParent[(int) $relation['parent_id']][] = $child;
            }
        }
    }

    /**
     * @param int[] $childIds
     * @return array<int, array{name: string, sku: string, attributes: array<string, list<string>>}>
     */
    private function loadChildren(array $childIds, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        $collection->addWebsiteFilter((int) $this->storeManager->getStore($storeId)->getWebsiteId());
        $collection->addFieldToFilter('entity_id', ['in' => $childIds]);

        $children = [];
        /** @var Product $child */
        foreach ($collection as $child) {
            $children[(int) $child->getId()] = [
                'name' => (string) $child->getName(),
                'sku' => (string) $child->getSku(),
                'attributes' => $this->attributeResolver->getExtraAttributes($child),
            ];
        }

        return $children;
    }
}
