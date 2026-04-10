<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class ShippingPerformanceTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'shipping_performance';
    }

    public function getDescription(): string
    {
        return 'Analyze shipping and fulfillment performance: average fulfillment time, shipping method usage breakdown, shipping cost as a percentage of order value, and free shipping rate.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['fulfillment_time', 'shipping_method_usage', 'shipping_cost_analysis', 'free_shipping_rate'],
                    'description' => 'The type of shipping performance analysis to perform.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Used for shipping_method_usage and shipping_cost_analysis.',
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

        $validAggregations = ['fulfillment_time', 'shipping_method_usage', 'shipping_cost_analysis', 'free_shipping_rate'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'fulfillment_time' => $this->fulfillmentTime(),
                'shipping_method_usage' => $this->shippingMethodUsage($limit),
                'shipping_cost_analysis' => $this->shippingCostAnalysis($limit),
                'free_shipping_rate' => $this->freeShippingRate(),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function fulfillmentTime(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $ss = $this->resource->getTableName('sales_shipment');

        $sql = "SELECT
                    COUNT(*) as shipment_count,
                    ROUND(AVG(DATEDIFF(ss.created_at, so.created_at)), 2) as avg_fulfillment_days,
                    ROUND(MIN(DATEDIFF(ss.created_at, so.created_at)), 2) as min_fulfillment_days,
                    ROUND(MAX(DATEDIFF(ss.created_at, so.created_at)), 2) as max_fulfillment_days,
                    SUM(CASE WHEN DATEDIFF(ss.created_at, so.created_at) = 0 THEN 1 ELSE 0 END) as same_day_count,
                    SUM(CASE WHEN DATEDIFF(ss.created_at, so.created_at) = 1 THEN 1 ELSE 0 END) as next_day_count,
                    SUM(CASE WHEN DATEDIFF(ss.created_at, so.created_at) > 1 THEN 1 ELSE 0 END) as over_one_day_count
                FROM {$so} so
                JOIN {$ss} ss ON ss.order_id = so.entity_id
                WHERE so.status != 'canceled'
                    AND DATEDIFF(ss.created_at, so.created_at) >= 0";

        $row = $connection->fetchRow($sql);

        return json_encode([
            'aggregation' => 'fulfillment_time',
            'shipment_count' => (int) ($row['shipment_count'] ?? 0),
            'avg_fulfillment_days' => round((float) ($row['avg_fulfillment_days'] ?? 0), 2),
            'min_fulfillment_days' => round((float) ($row['min_fulfillment_days'] ?? 0), 2),
            'max_fulfillment_days' => round((float) ($row['max_fulfillment_days'] ?? 0), 2),
            'same_day_count' => (int) ($row['same_day_count'] ?? 0),
            'next_day_count' => (int) ($row['next_day_count'] ?? 0),
            'over_one_day_count' => (int) ($row['over_one_day_count'] ?? 0),
        ]);
    }

    private function shippingMethodUsage(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $totalSql = "SELECT COUNT(*) as total FROM {$so} WHERE status != 'canceled' AND shipping_description IS NOT NULL AND shipping_description != ''";
        $totalResult = $connection->fetchRow($totalSql);
        $total = max(1, (int) ($totalResult['total'] ?? 1));

        $sql = "SELECT
                    shipping_description,
                    COUNT(*) as order_count,
                    ROUND(SUM(shipping_amount), 2) as total_shipping_revenue,
                    ROUND(AVG(shipping_amount), 2) as avg_shipping_cost
                FROM {$so}
                WHERE status != 'canceled'
                    AND shipping_description IS NOT NULL
                    AND shipping_description != ''
                GROUP BY shipping_description
                ORDER BY order_count DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'shipping_method_usage',
            'rows' => array_map(fn(array $r) => [
                'shipping_method' => $r['shipping_description'],
                'order_count' => (int) $r['order_count'],
                'usage_pct' => round((int) $r['order_count'] / $total * 100, 2),
                'total_shipping_revenue' => round((float) $r['total_shipping_revenue'], 2),
                'avg_shipping_cost' => round((float) $r['avg_shipping_cost'], 2),
            ], $rows),
        ]);
    }

    private function shippingCostAnalysis(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soa = $this->resource->getTableName('sales_order_address');

        $sql = "SELECT
                    soa.country_id,
                    COUNT(DISTINCT so.entity_id) as order_count,
                    ROUND(AVG(so.shipping_amount), 2) as avg_shipping_cost,
                    ROUND(AVG(so.grand_total), 2) as avg_order_value,
                    ROUND(AVG(CASE WHEN so.grand_total > 0 THEN so.shipping_amount / so.grand_total * 100 ELSE 0 END), 2) as shipping_cost_pct_of_order
                FROM {$so} so
                JOIN {$soa} soa ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping'
                WHERE so.status != 'canceled'
                    AND so.shipping_amount > 0
                GROUP BY soa.country_id
                ORDER BY order_count DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'shipping_cost_analysis',
            'rows' => array_map(fn(array $r) => [
                'country_id' => $r['country_id'],
                'order_count' => (int) $r['order_count'],
                'avg_shipping_cost' => round((float) $r['avg_shipping_cost'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
                'shipping_cost_pct_of_order' => round((float) $r['shipping_cost_pct_of_order'], 2),
            ], $rows),
        ]);
    }

    private function freeShippingRate(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN shipping_amount = 0 THEN 1 ELSE 0 END) as free_shipping_orders,
                    SUM(CASE WHEN shipping_amount > 0 THEN 1 ELSE 0 END) as paid_shipping_orders,
                    ROUND(SUM(shipping_amount), 2) as total_shipping_collected,
                    ROUND(AVG(CASE WHEN shipping_amount > 0 THEN shipping_amount END), 2) as avg_paid_shipping_cost
                FROM {$so}
                WHERE status != 'canceled'";

        $row = $connection->fetchRow($sql);

        $totalOrders = max(1, (int) ($row['total_orders'] ?? 1));
        $freeOrders = (int) ($row['free_shipping_orders'] ?? 0);

        return json_encode([
            'aggregation' => 'free_shipping_rate',
            'total_orders' => $totalOrders,
            'free_shipping_orders' => $freeOrders,
            'paid_shipping_orders' => (int) ($row['paid_shipping_orders'] ?? 0),
            'free_shipping_rate_pct' => round($freeOrders / $totalOrders * 100, 2),
            'total_shipping_collected' => round((float) ($row['total_shipping_collected'] ?? 0), 2),
            'avg_paid_shipping_cost' => round((float) ($row['avg_paid_shipping_cost'] ?? 0), 2),
        ]);
    }
}
