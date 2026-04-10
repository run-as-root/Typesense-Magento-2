<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class ProductVelocityTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'product_velocity';
    }

    public function getDescription(): string
    {
        return 'Analyze product sales velocity. Identify fast movers, slow movers, dead stock, and sell-through rates over a configurable time window.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['fast_movers', 'slow_movers', 'dead_stock', 'sell_through_rate'],
                    'description' => 'The type of velocity analysis to perform.',
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'The number of days in the velocity window (default 30).',
                    'default' => 30,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10).',
                    'default' => 10,
                ],
            ],
            'required' => ['aggregation'],
        ];
    }

    public function execute(array $arguments): string
    {
        $aggregation = $arguments['aggregation'] ?? '';
        $days = max(1, (int) ($arguments['days'] ?? 30));
        $limit = max(1, (int) ($arguments['limit'] ?? 10));

        $validAggregations = ['fast_movers', 'slow_movers', 'dead_stock', 'sell_through_rate'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'fast_movers' => $this->fastMovers($days, $limit),
                'slow_movers' => $this->slowMovers($days, $limit),
                'dead_stock' => $this->deadStock($days, $limit),
                'sell_through_rate' => $this->sellThroughRate($days, $limit),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function fastMovers(int $days, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $soi = $this->resource->getTableName('sales_order_item');
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT soi.sku, soi.name,
                    SUM(soi.qty_ordered) as units_sold,
                    ROUND(SUM(soi.qty_ordered) / :days, 4) as units_per_day,
                    SUM(soi.row_total) as revenue
                FROM {$soi} soi
                JOIN {$so} so ON so.entity_id = soi.order_id
                WHERE soi.parent_item_id IS NULL
                    AND so.status != 'canceled'
                    AND so.created_at >= DATE_SUB(NOW(), INTERVAL :days2 DAY)
                GROUP BY soi.sku, soi.name
                ORDER BY units_per_day DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['days' => $days, 'days2' => $days, 'limit' => $limit]);

        return json_encode([
            'aggregation' => 'fast_movers',
            'days' => $days,
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'name' => $r['name'],
                'units_sold' => (int) $r['units_sold'],
                'units_per_day' => round((float) $r['units_per_day'], 4),
                'revenue' => round((float) $r['revenue'], 2),
            ], $rows),
        ]);
    }

    private function slowMovers(int $days, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $soi = $this->resource->getTableName('sales_order_item');
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT soi.sku, soi.name,
                    SUM(soi.qty_ordered) as units_sold,
                    ROUND(SUM(soi.qty_ordered) / :days, 4) as units_per_day,
                    SUM(soi.row_total) as revenue
                FROM {$soi} soi
                JOIN {$so} so ON so.entity_id = soi.order_id
                WHERE soi.parent_item_id IS NULL
                    AND so.status != 'canceled'
                    AND so.created_at >= DATE_SUB(NOW(), INTERVAL :days2 DAY)
                GROUP BY soi.sku, soi.name
                HAVING units_sold > 0
                ORDER BY units_per_day ASC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['days' => $days, 'days2' => $days, 'limit' => $limit]);

        return json_encode([
            'aggregation' => 'slow_movers',
            'days' => $days,
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'name' => $r['name'],
                'units_sold' => (int) $r['units_sold'],
                'units_per_day' => round((float) $r['units_per_day'], 4),
                'revenue' => round((float) $r['revenue'], 2),
            ], $rows),
        ]);
    }

    private function deadStock(int $days, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $csi = $this->resource->getTableName('cataloginventory_stock_item');
        $soi = $this->resource->getTableName('sales_order_item');
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT cpe.sku, csi.qty as current_stock
                FROM {$cpe} cpe
                JOIN {$csi} csi ON csi.product_id = cpe.entity_id
                WHERE csi.qty > 0
                    AND cpe.sku NOT IN (
                        SELECT DISTINCT soi.sku
                        FROM {$soi} soi
                        JOIN {$so} so ON so.entity_id = soi.order_id
                        WHERE so.status != 'canceled'
                            AND so.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                            AND soi.parent_item_id IS NULL
                    )
                ORDER BY csi.qty DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['days' => $days, 'limit' => $limit]);

        return json_encode([
            'aggregation' => 'dead_stock',
            'days' => $days,
            'description' => "Products with stock > 0 and no sales in last {$days} days",
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'current_stock' => round((float) $r['current_stock'], 2),
            ], $rows),
        ]);
    }

    private function sellThroughRate(int $days, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $soi = $this->resource->getTableName('sales_order_item');
        $so = $this->resource->getTableName('sales_order');
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $csi = $this->resource->getTableName('cataloginventory_stock_item');

        $sql = "SELECT soi.sku, soi.name,
                    SUM(soi.qty_ordered) as units_sold,
                    COALESCE(csi.qty, 0) as current_stock,
                    CASE
                        WHEN SUM(soi.qty_ordered) + COALESCE(csi.qty, 0) > 0
                        THEN ROUND(SUM(soi.qty_ordered) / (SUM(soi.qty_ordered) + COALESCE(csi.qty, 0)) * 100, 2)
                        ELSE 0
                    END as sell_through_rate_pct
                FROM {$soi} soi
                JOIN {$so} so ON so.entity_id = soi.order_id
                LEFT JOIN {$cpe} cpe ON cpe.sku = soi.sku
                LEFT JOIN {$csi} csi ON csi.product_id = cpe.entity_id
                WHERE soi.parent_item_id IS NULL
                    AND so.status != 'canceled'
                    AND so.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY soi.sku, soi.name, csi.qty
                ORDER BY sell_through_rate_pct DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['days' => $days, 'limit' => $limit]);

        return json_encode([
            'aggregation' => 'sell_through_rate',
            'days' => $days,
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'name' => $r['name'],
                'units_sold' => (int) $r['units_sold'],
                'current_stock' => round((float) $r['current_stock'], 2),
                'sell_through_rate_pct' => round((float) $r['sell_through_rate_pct'], 2),
            ], $rows),
        ]);
    }
}
