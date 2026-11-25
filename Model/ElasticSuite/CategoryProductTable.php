<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Magento's category product assignments (catalog_category_product), read and written in bulk.
 */
class CategoryProductTable
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
    ) {
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, array{path: string, is_anchor: bool}> by category id
     */
    public function getCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $anchorAttributeId = (int) $connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['t' => $this->resource->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    [],
                )
                ->where('t.entity_type_code = ?', Category::ENTITY)
                ->where('a.attribute_code = ?', 'is_anchor'),
        );
        $select = $connection->select()
            ->from(['c' => $this->resource->getTableName('catalog_category_entity')], ['entity_id', 'path'])
            ->joinLeft(
                ['anchor' => $this->resource->getTableName('catalog_category_entity_int')],
                sprintf(
                    'anchor.%1$s = c.%1$s AND anchor.store_id = 0 AND anchor.attribute_id = %2$d',
                    $linkField,
                    $anchorAttributeId,
                ),
                ['is_anchor' => 'value'],
            )
            ->where('c.entity_id IN (?)', $categoryIds);
        $rows = $connection->fetchAll($select);

        $categories = [];
        foreach ($rows as $row) {
            $categories[(int) $row['entity_id']] = [
                'path' => (string) $row['path'],
                'is_anchor' => (bool) $row['is_anchor'],
            ];
        }

        return $categories;
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, array<int, int>> position by product id, by category id
     */
    public function getAssignedPositions(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_category_product'), ['category_id', 'product_id', 'position'])
            ->where('category_id IN (?)', $categoryIds);
        $rows = $connection->fetchAll($select);

        $positions = [];
        foreach ($rows as $row) {
            $positions[(int) $row['category_id']][(int) $row['product_id']] = (int) $row['position'];
        }

        return $positions;
    }

    /**
     * @param int[] $productIds
     * @return array<int, list<string>> paths of the categories each product is assigned to, by product id
     */
    public function getAssignedCategoryPaths(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ccp' => $this->resource->getTableName('catalog_category_product')], ['product_id'])
            ->join(
                ['c' => $this->resource->getTableName('catalog_category_entity')],
                'c.entity_id = ccp.category_id',
                ['path'],
            )
            ->where('ccp.product_id IN (?)', $productIds);
        $rows = $connection->fetchAll($select);

        $paths = [];
        foreach ($rows as $row) {
            $paths[(int) $row['product_id']][] = (string) $row['path'];
        }

        return $paths;
    }

    /**
     * Inserts missing assignments and updates the position of existing ones; the mview triggers on
     * catalog_category_product queue the products for reindexing.
     *
     * @param array<int, int> $positions position by product id
     */
    public function savePositions(int $categoryId, array $positions): void
    {
        if ($positions === []) {
            return;
        }

        $rows = [];
        foreach ($positions as $productId => $position) {
            $rows[] = ['category_id' => $categoryId, 'product_id' => $productId, 'position' => $position];
        }

        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName('catalog_category_product'),
            $rows,
            ['position'],
        );
    }
}
