<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class DiscountEffectivenessTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'discount_effectiveness';
    }

    public function getDescription(): string
    {
        return 'Analyze how discounts and coupon codes affect sales. Supports coupon performance breakdown, discount impact on order value, top revenue-driving coupons, and comparison of coupon vs non-coupon buyers.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['coupon_performance', 'discount_impact', 'top_coupons', 'coupon_vs_no_coupon'],
                    'description' => 'The discount analysis to perform.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Not applicable to discount_impact and coupon_vs_no_coupon.',
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

        $validAggregations = ['coupon_performance', 'discount_impact', 'top_coupons', 'coupon_vs_no_coupon'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'coupon_performance' => $this->couponPerformance($limit),
                'discount_impact' => $this->discountImpact(),
                'top_coupons' => $this->topCoupons($limit),
                'coupon_vs_no_coupon' => $this->couponVsNoCoupon(),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function couponPerformance(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT coupon_code,
                    COUNT(*) as uses,
                    SUM(ABS(discount_amount)) as total_discount,
                    SUM(grand_total) as total_revenue,
                    AVG(grand_total) as avg_order_value,
                    AVG(ABS(discount_amount)) as avg_discount
                FROM {$so}
                WHERE coupon_code IS NOT NULL AND coupon_code != '' AND status != 'canceled'
                GROUP BY coupon_code
                ORDER BY uses DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'coupon_performance',
            'rows' => array_map(fn(array $r) => [
                'coupon_code' => $r['coupon_code'],
                'uses' => (int) $r['uses'],
                'total_discount' => round((float) $r['total_discount'], 2),
                'total_revenue' => round((float) $r['total_revenue'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
                'avg_discount' => round((float) $r['avg_discount'], 2),
            ], $rows),
        ]);
    }

    private function discountImpact(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    SUM(CASE WHEN coupon_code IS NOT NULL AND coupon_code != '' THEN 1 ELSE 0 END) as coupon_orders,
                    SUM(CASE WHEN coupon_code IS NULL OR coupon_code = '' THEN 1 ELSE 0 END) as no_coupon_orders,
                    AVG(CASE WHEN coupon_code IS NOT NULL AND coupon_code != '' THEN grand_total ELSE NULL END) as avg_order_value_with_coupon,
                    AVG(CASE WHEN coupon_code IS NULL OR coupon_code = '' THEN grand_total ELSE NULL END) as avg_order_value_without_coupon,
                    AVG(CASE WHEN coupon_code IS NOT NULL AND coupon_code != '' THEN ABS(discount_amount) ELSE NULL END) as avg_discount_amount,
                    SUM(ABS(discount_amount)) as total_discounts_given,
                    SUM(grand_total) as total_revenue
                FROM {$so}
                WHERE status != 'canceled'";

        $row = $connection->fetchRow($sql);

        return json_encode([
            'aggregation' => 'discount_impact',
            'coupon_orders' => (int) ($row['coupon_orders'] ?? 0),
            'no_coupon_orders' => (int) ($row['no_coupon_orders'] ?? 0),
            'avg_order_value_with_coupon' => round((float) ($row['avg_order_value_with_coupon'] ?? 0), 2),
            'avg_order_value_without_coupon' => round((float) ($row['avg_order_value_without_coupon'] ?? 0), 2),
            'avg_discount_amount' => round((float) ($row['avg_discount_amount'] ?? 0), 2),
            'total_discounts_given' => round((float) ($row['total_discounts_given'] ?? 0), 2),
            'total_revenue' => round((float) ($row['total_revenue'] ?? 0), 2),
        ]);
    }

    private function topCoupons(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT coupon_code,
                    COUNT(*) as uses,
                    SUM(grand_total) as total_revenue,
                    SUM(ABS(discount_amount)) as total_discount,
                    AVG(grand_total) as avg_order_value
                FROM {$so}
                WHERE coupon_code IS NOT NULL AND coupon_code != '' AND status != 'canceled'
                GROUP BY coupon_code
                ORDER BY total_revenue DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'top_coupons',
            'rows' => array_map(fn(array $r) => [
                'coupon_code' => $r['coupon_code'],
                'uses' => (int) $r['uses'],
                'total_revenue' => round((float) $r['total_revenue'], 2),
                'total_discount' => round((float) $r['total_discount'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
            ], $rows),
        ]);
    }

    private function couponVsNoCoupon(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        // Repeat purchase rate: customers who placed more than one order, segmented by whether they ever used a coupon
        $sql = "SELECT
                    SUM(CASE WHEN has_coupon = 1 AND order_count > 1 THEN 1 ELSE 0 END) as coupon_repeat_customers,
                    SUM(CASE WHEN has_coupon = 1 THEN 1 ELSE 0 END) as total_coupon_customers,
                    SUM(CASE WHEN has_coupon = 0 AND order_count > 1 THEN 1 ELSE 0 END) as no_coupon_repeat_customers,
                    SUM(CASE WHEN has_coupon = 0 THEN 1 ELSE 0 END) as total_no_coupon_customers,
                    AVG(CASE WHEN has_coupon = 1 THEN total_spend ELSE NULL END) as avg_ltv_coupon,
                    AVG(CASE WHEN has_coupon = 0 THEN total_spend ELSE NULL END) as avg_ltv_no_coupon
                FROM (
                    SELECT customer_id,
                        COUNT(*) as order_count,
                        SUM(grand_total) as total_spend,
                        MAX(CASE WHEN coupon_code IS NOT NULL AND coupon_code != '' THEN 1 ELSE 0 END) as has_coupon
                    FROM {$so}
                    WHERE customer_id IS NOT NULL AND status != 'canceled'
                    GROUP BY customer_id
                ) customer_summary";

        $row = $connection->fetchRow($sql);

        $totalCoupon = (int) ($row['total_coupon_customers'] ?? 0);
        $totalNoCoupon = (int) ($row['total_no_coupon_customers'] ?? 0);
        $couponRepeat = (int) ($row['coupon_repeat_customers'] ?? 0);
        $noCouponRepeat = (int) ($row['no_coupon_repeat_customers'] ?? 0);

        return json_encode([
            'aggregation' => 'coupon_vs_no_coupon',
            'coupon_customers' => [
                'total' => $totalCoupon,
                'repeat_buyers' => $couponRepeat,
                'repeat_rate_pct' => $totalCoupon > 0 ? round($couponRepeat / $totalCoupon * 100, 2) : null,
                'avg_ltv' => round((float) ($row['avg_ltv_coupon'] ?? 0), 2),
            ],
            'no_coupon_customers' => [
                'total' => $totalNoCoupon,
                'repeat_buyers' => $noCouponRepeat,
                'repeat_rate_pct' => $totalNoCoupon > 0 ? round($noCouponRepeat / $totalNoCoupon * 100, 2) : null,
                'avg_ltv' => round((float) ($row['avg_ltv_no_coupon'] ?? 0), 2),
            ],
        ]);
    }
}
