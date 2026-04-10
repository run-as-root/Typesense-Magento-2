<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class TimePatternAnalysisTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'time_pattern_analysis';
    }

    public function getDescription(): string
    {
        return 'Analyze order and revenue patterns over time. Supports hourly breakdown, day-of-week analysis, monthly trends, peak hour identification, and seasonal product variation.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['by_hour', 'by_day_of_week', 'by_month', 'peak_hours', 'seasonal_products'],
                    'description' => 'The type of time pattern analysis to perform.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Used for by_month and seasonal_products.',
                    'default' => 10,
                ],
            ],
            'required' => ['aggregation'],
        ];
    }

    public function execute(array $arguments): string
    {
        $aggregation = $arguments['aggregation'] ?? '';
        $limit = max(1, (int) ($arguments['limit'] ?? 10));

        $validAggregations = ['by_hour', 'by_day_of_week', 'by_month', 'peak_hours', 'seasonal_products'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'by_hour' => $this->byHour(),
                'by_day_of_week' => $this->byDayOfWeek(),
                'by_month' => $this->byMonth($limit),
                'peak_hours' => $this->peakHours(),
                'seasonal_products' => $this->seasonalProducts($limit),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function byHour(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    HOUR(created_at) as hour,
                    COUNT(*) as order_count,
                    ROUND(SUM(grand_total), 2) as revenue,
                    ROUND(AVG(grand_total), 2) as avg_order_value
                FROM {$so}
                WHERE status != 'canceled'
                GROUP BY HOUR(created_at)
                ORDER BY hour ASC";

        $rows = $connection->fetchAll($sql);

        return json_encode([
            'aggregation' => 'by_hour',
            'rows' => array_map(fn(array $r) => [
                'hour' => (int) $r['hour'],
                'hour_label' => sprintf('%02d:00', (int) $r['hour']),
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
            ], $rows),
        ]);
    }

    private function byDayOfWeek(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    DAYOFWEEK(created_at) as day_number,
                    DAYNAME(created_at) as day_name,
                    COUNT(*) as order_count,
                    ROUND(SUM(grand_total), 2) as revenue,
                    ROUND(AVG(grand_total), 2) as avg_order_value
                FROM {$so}
                WHERE status != 'canceled'
                GROUP BY DAYOFWEEK(created_at), DAYNAME(created_at)
                ORDER BY day_number ASC";

        $rows = $connection->fetchAll($sql);

        return json_encode([
            'aggregation' => 'by_day_of_week',
            'rows' => array_map(fn(array $r) => [
                'day_number' => (int) $r['day_number'],
                'day_name' => $r['day_name'],
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
            ], $rows),
        ]);
    }

    private function byMonth(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as order_count,
                    ROUND(SUM(grand_total), 2) as revenue,
                    ROUND(AVG(grand_total), 2) as avg_order_value
                FROM {$so}
                WHERE status != 'canceled'
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'by_month',
            'rows' => array_map(fn(array $r) => [
                'month' => $r['month'],
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
            ], $rows),
        ]);
    }

    private function peakHours(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    HOUR(created_at) as hour,
                    COUNT(*) as order_count,
                    ROUND(SUM(grand_total), 2) as revenue
                FROM {$so}
                WHERE status != 'canceled'
                GROUP BY HOUR(created_at)
                ORDER BY order_count DESC
                LIMIT 5";

        $rows = $connection->fetchAll($sql);

        return json_encode([
            'aggregation' => 'peak_hours',
            'description' => 'Top 5 hours of the day by order volume',
            'rows' => array_map(fn(array $r) => [
                'hour' => (int) $r['hour'],
                'hour_label' => sprintf('%02d:00 - %02d:00', (int) $r['hour'], ((int) $r['hour'] + 1) % 24),
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
            ], $rows),
        ]);
    }

    private function seasonalProducts(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');

        // Products with highest coefficient of variation in monthly sales (std dev / avg)
        $sql = "SELECT
                    sku,
                    product_name,
                    ROUND(AVG(monthly_units), 2) as avg_monthly_units,
                    ROUND(STD(monthly_units), 2) as std_monthly_units,
                    ROUND(STD(monthly_units) / NULLIF(AVG(monthly_units), 0) * 100, 2) as variance_coefficient_pct,
                    COUNT(*) as active_months
                FROM (
                    SELECT
                        soi.sku,
                        soi.name as product_name,
                        DATE_FORMAT(so.created_at, '%Y-%m') as month,
                        SUM(soi.qty_ordered) as monthly_units
                    FROM {$soi} soi
                    JOIN {$so} so ON so.entity_id = soi.order_id
                    WHERE so.status != 'canceled'
                        AND soi.parent_item_id IS NULL
                    GROUP BY soi.sku, soi.name, DATE_FORMAT(so.created_at, '%Y-%m')
                ) monthly_sales
                GROUP BY sku, product_name
                HAVING active_months >= 3
                ORDER BY variance_coefficient_pct DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'seasonal_products',
            'description' => 'Products with highest monthly sales variance (coefficient of variation). Higher % = more seasonal.',
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'product_name' => $r['product_name'],
                'avg_monthly_units' => round((float) $r['avg_monthly_units'], 2),
                'std_monthly_units' => round((float) $r['std_monthly_units'], 2),
                'variance_coefficient_pct' => round((float) $r['variance_coefficient_pct'], 2),
                'active_months' => (int) $r['active_months'],
            ], $rows),
        ]);
    }
}
