<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;

class ParentIdResolver
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * @param int[] $childIds
     * @return int[]
     */
    public function getParentIds(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id'])
            ->where('child_id IN (?)', $childIds);

        $parentIds = $connection->fetchCol($select);

        return array_values(array_unique(array_map('intval', $parentIds)));
    }
}
