<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class InventoryForecastTool implements ToolInterface
{
    private const CRITICAL_DAYS_THRESHOLD = 7;

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'inventory_forecast';
    }

    public function getDescription(): string
    {
        return 'Forecast stock depletion for products based on recent sales velocity. Identifies which products will run out of stock and when, with critical/warning/ok status.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days_lookback' => [
                    'type' => 'integer',
                    'description' => 'Number of days of sales history to use for calculating average daily sales (default: 30).',
                    'default' => 30,
                ],
                'alert_threshold' => [
                    'type' => 'integer',
                    'description' => 'Number of days until stockout to trigger a warning status (default: 14).',
                    'default' => 14,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of products to return, ordered by urgency (default: 20).',
                    'default' => 20,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): string
    {
        $daysLookback = max(1, (int) ($arguments['days_lookback'] ?? 30));
        $alertThreshold = max(1, (int) ($arguments['alert_threshold'] ?? 14));
        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 20)));

        try {
            $products = $this->fetchInventoryData($daysLookback, $limit);
            $forecasted = $this->applyForecast($products, $daysLookback, $alertThreshold);

            return json_encode([
                'days_lookback' => $daysLookback,
                'alert_threshold_days' => $alertThreshold,
                'products' => $forecasted,
                'count' => count($forecasted),
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchInventoryData(int $daysLookback, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $productTable = $this->resource->getTableName('catalog_product_entity');
        $stockTable = $this->resource->getTableName('cataloginventory_stock_item');
        $orderItemTable = $this->resource->getTableName('sales_order_item');

        $sql = "SELECT
                    cpe.sku,
                    cpe.entity_id as product_id,
                    csi.qty as current_stock,
                    COALESCE(SUM(soi.qty_ordered) / :days_lookback, 0) as avg_daily_sales
                FROM {$productTable} cpe
                JOIN {$stockTable} csi
                    ON csi.product_id = cpe.entity_id
                    AND csi.stock_id = 1
                LEFT JOIN {$orderItemTable} soi
                    ON soi.product_id = cpe.entity_id
                    AND soi.parent_item_id IS NULL
                    AND soi.created_at >= DATE_SUB(NOW(), INTERVAL :days_lookback DAY)
                GROUP BY cpe.entity_id, cpe.sku, csi.qty
                HAVING avg_daily_sales > 0
                ORDER BY (csi.qty / avg_daily_sales) ASC
                LIMIT :limit";

        return $connection->fetchAll($sql, [
            'days_lookback' => $daysLookback,
            'limit' => $limit,
        ]);
    }

    /**
     * Apply status classification and days_until_stockout calculation.
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function applyForecast(array $products, int $daysLookback, int $alertThreshold): array
    {
        return array_map(function (array $product) use ($alertThreshold): array {
            $currentStock = (float) $product['current_stock'];
            $avgDailySales = (float) $product['avg_daily_sales'];

            $daysUntilStockout = $avgDailySales > 0
                ? (int) round($currentStock / $avgDailySales)
                : null;

            $status = $this->classifyStatus($daysUntilStockout, $alertThreshold);

            return [
                'sku' => $product['sku'],
                'current_stock' => (int) $currentStock,
                'avg_daily_sales' => round($avgDailySales, 2),
                'days_until_stockout' => $daysUntilStockout,
                'status' => $status,
            ];
        }, $products);
    }

    private function classifyStatus(?int $daysUntilStockout, int $alertThreshold): string
    {
        if ($daysUntilStockout === null) {
            return 'ok';
        }

        if ($daysUntilStockout < self::CRITICAL_DAYS_THRESHOLD) {
            return 'critical';
        }

        if ($daysUntilStockout < $alertThreshold) {
            return 'warning';
        }

        return 'ok';
    }
}
