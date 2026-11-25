<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;

/**
 * Magento category positions as one sortable field per category (category_position_<id>), so a category
 * listing sorts by its manual order. Positions come from catalog_category_product, computed like Magento's
 * category product index rather than read from it, so a stale index cannot reorder listings.
 */
class CategoryPositionResolver
{
    public const string FIELD_PREFIX = 'category_position_';

    // Magento's anchor index lists products a category only inherits from a descendant after its own ones:
    // IFNULL(assigned position, MIN(descendant position) + 10000).
    private const int INHERITED_OFFSET = 10000;

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public static function fieldName(int $categoryId): string
    {
        return self::FIELD_PREFIX . $categoryId;
    }

    /**
     * @return array{name: string, type: string, optional: bool, sort: bool}
     */
    public function getSchemaField(): array
    {
        return ['name' => self::FIELD_PREFIX . '.*', 'type' => 'int32', 'optional' => true, 'sort' => true];
    }

    /**
     * Positions of a whole indexing batch with one query: the assigned categories' own positions, and for every
     * ancestor the lowest descendant position plus the anchor offset.
     *
     * @param int[] $productIds
     * @return array<int, array<int, int>> position by category id, by product id
     */
    public function load(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['ccp' => $this->resource->getTableName('catalog_category_product')],
                ['product_id', 'category_id', 'position'],
            )
            ->join(
                ['c' => $this->resource->getTableName('catalog_category_entity')],
                'c.entity_id = ccp.category_id',
                ['path'],
            )
            ->where('ccp.product_id IN (?)', $productIds);
        $rows = $connection->fetchAll($select);

        $assigned = [];
        $inherited = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $position = (int) $row['position'];
            $assigned[$productId][(int) $row['category_id']] = $position;

            $ancestorIds = array_slice(explode('/', (string) $row['path']), 0, -1);
            foreach ($ancestorIds as $ancestorId) {
                $ancestorId = (int) $ancestorId;
                $inherited[$productId][$ancestorId] = min(
                    $inherited[$productId][$ancestorId] ?? PHP_INT_MAX,
                    $position + self::INHERITED_OFFSET,
                );
            }
        }

        $positions = [];
        foreach ($assigned as $productId => $categoryPositions) {
            $positions[$productId] = $categoryPositions + ($inherited[$productId] ?? []);
        }

        return $positions;
    }

    /**
     * Only the document's own categories get a field: category_ids already holds what Magento lists the product in.
     *
     * @param array<int, int> $positions position by category id
     * @param int[] $categoryIds
     * @return array<string, int>
     */
    public function toFields(array $positions, array $categoryIds): array
    {
        $fields = [];
        foreach ($categoryIds as $categoryId) {
            if (isset($positions[$categoryId])) {
                $fields[self::fieldName((int) $categoryId)] = $positions[$categoryId];
            }
        }

        return $fields;
    }
}
