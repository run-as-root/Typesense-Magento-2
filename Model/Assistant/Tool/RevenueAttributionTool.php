<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class RevenueAttributionTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'revenue_attribution';
    }

    public function getDescription(): string
    {
        return 'Analyze revenue attribution by coupon code or provide a summary of coupon vs non-coupon revenue split.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['by_coupon_code', 'by_source', 'summary'],
                    'description' => 'The type of revenue attribution analysis. by_source falls back to coupon-based attribution if order source tracking is unavailable.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Not applicable to summary.',
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

        $validAggregations = ['by_coupon_code', 'by_source', 'summary'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'by_coupon_code' => $this->byCouponCode($limit),
                'by_source' => $this->bySource($limit),
                'summary' => $this->summary(),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function byCouponCode(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    coupon_code,
                    COUNT(*) as order_count,
                    SUM(grand_total) as revenue,
                    ROUND(AVG(grand_total), 2) as avg_order_value,
                    SUM(discount_amount) as total_discount
                FROM {$so}
                WHERE coupon_code IS NOT NULL
                    AND coupon_code != ''
                    AND status != 'canceled'
                GROUP BY coupon_code
                ORDER BY revenue DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'by_coupon_code',
            'rows' => array_map(fn(array $r) => [
                'coupon_code' => $r['coupon_code'],
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
                'total_discount' => round((float) $r['total_discount'], 2),
            ], $rows),
        ]);
    }

    private function bySource(int $limit): string
    {
        // Magento core does not store UTM/referral source on orders.
        // Fall back to coupon-based attribution grouped into "attributed" vs "organic".
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    coupon_code,
                    COUNT(*) as order_count,
                    SUM(grand_total) as revenue,
                    ROUND(AVG(grand_total), 2) as avg_order_value
                FROM {$so}
                WHERE coupon_code IS NOT NULL
                    AND coupon_code != ''
                    AND status != 'canceled'
                GROUP BY coupon_code
                ORDER BY revenue DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'by_source',
            'note' => 'Magento does not natively track UTM/referral source on orders. Showing coupon-based attribution as a proxy.',
            'rows' => array_map(fn(array $r) => [
                'source' => $r['coupon_code'],
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
            ], $rows),
        ]);
    }

    private function summary(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    SUM(CASE WHEN coupon_code IS NOT NULL AND coupon_code != '' THEN 1 ELSE 0 END) as coupon_orders,
                    SUM(CASE WHEN coupon_code IS NULL OR coupon_code = '' THEN 1 ELSE 0 END) as non_coupon_orders,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN coupon_code IS NOT NULL AND coupon_code != '' THEN grand_total ELSE 0 END) as coupon_revenue,
                    SUM(CASE WHEN coupon_code IS NULL OR coupon_code = '' THEN grand_total ELSE 0 END) as non_coupon_revenue,
                    SUM(grand_total) as total_revenue,
                    SUM(discount_amount) as total_discount_given
                FROM {$so}
                WHERE status != 'canceled'";

        $row = $connection->fetchRow($sql);

        $totalRevenue = (float) ($row['total_revenue'] ?? 0);
        $couponRevenue = (float) ($row['coupon_revenue'] ?? 0);
        $nonCouponRevenue = (float) ($row['non_coupon_revenue'] ?? 0);

        return json_encode([
            'aggregation' => 'summary',
            'total_orders' => (int) ($row['total_orders'] ?? 0),
            'coupon_orders' => (int) ($row['coupon_orders'] ?? 0),
            'non_coupon_orders' => (int) ($row['non_coupon_orders'] ?? 0),
            'total_revenue' => round($totalRevenue, 2),
            'coupon_revenue' => round($couponRevenue, 2),
            'non_coupon_revenue' => round($nonCouponRevenue, 2),
            'coupon_revenue_pct' => $totalRevenue > 0 ? round($couponRevenue / $totalRevenue * 100, 2) : 0,
            'total_discount_given' => round((float) ($row['total_discount_given'] ?? 0), 2),
        ]);
    }
}
