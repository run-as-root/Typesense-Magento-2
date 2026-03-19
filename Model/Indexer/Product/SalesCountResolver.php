<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;

class SalesCountResolver implements SalesCountResolverInterface
{
    private ?array $cache = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function getSalesCount(int $productId): int
    {
        $this->loadAll();

        return $this->cache[$productId] ?? 0;
    }

    private function loadAll(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $this->cache = [];

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sales_bestsellers_aggregated_daily');

        if (!$connection->isTableExists($tableName)) {
            return;
        }

        $select = $connection->select()
            ->from($tableName, ['product_id', 'qty_ordered' => new \Zend_Db_Expr('SUM(qty_ordered)')])
            ->group('product_id');

        $rows = $connection->fetchAll($select);

        foreach ($rows as $row) {
            $this->cache[(int) $row['product_id']] = (int) $row['qty_ordered'];
        }
    }
}
