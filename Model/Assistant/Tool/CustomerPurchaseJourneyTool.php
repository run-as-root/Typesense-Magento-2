<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class CustomerPurchaseJourneyTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'customer_purchase_journey';
    }

    public function getDescription(): string
    {
        return 'Analyze customer purchase journey patterns: first-to-second product transitions, entry products by lifetime value, common 2-product sequences, and repeat product purchase rate.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['first_to_second_product', 'entry_products_by_ltv', 'common_sequences', 'repeat_product_rate'],
                    'description' => 'The type of purchase journey analysis to perform.',
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
        $limit = max(1, (int) ($arguments['limit'] ?? 10));

        $validAggregations = ['first_to_second_product', 'entry_products_by_ltv', 'common_sequences', 'repeat_product_rate'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'first_to_second_product' => $this->firstToSecondProduct($limit),
                'entry_products_by_ltv' => $this->entryProductsByLtv($limit),
                'common_sequences' => $this->commonSequences($limit),
                'repeat_product_rate' => $this->repeatProductRate($limit),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function firstToSecondProduct(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');

        // Rank orders per customer chronologically, then join order 1 items with order 2 items
        $sql = "SELECT
                    first_sku,
                    first_product_name,
                    second_sku,
                    second_product_name,
                    COUNT(*) as transition_count
                FROM (
                    SELECT
                        o1.customer_email,
                        i1.sku as first_sku,
                        i1.name as first_product_name,
                        i2.sku as second_sku,
                        i2.name as second_product_name
                    FROM {$so} o1
                    JOIN {$so} o2 ON o2.customer_email = o1.customer_email
                        AND o2.created_at > o1.created_at
                    JOIN {$soi} i1 ON i1.order_id = o1.entity_id AND i1.parent_item_id IS NULL
                    JOIN {$soi} i2 ON i2.order_id = o2.entity_id AND i2.parent_item_id IS NULL
                    WHERE o1.customer_email IS NOT NULL
                        AND o1.status != 'canceled'
                        AND o2.status != 'canceled'
                        AND NOT EXISTS (
                            SELECT 1 FROM {$so} o0
                            WHERE o0.customer_email = o1.customer_email
                                AND o0.created_at < o1.created_at
                                AND o0.status != 'canceled'
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM {$so} omid
                            WHERE omid.customer_email = o1.customer_email
                                AND omid.created_at > o1.created_at
                                AND omid.created_at < o2.created_at
                                AND omid.status != 'canceled'
                        )
                ) transitions
                GROUP BY first_sku, first_product_name, second_sku, second_product_name
                ORDER BY transition_count DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'first_to_second_product',
            'rows' => array_map(fn(array $r) => [
                'first_sku' => $r['first_sku'],
                'first_product_name' => $r['first_product_name'],
                'second_sku' => $r['second_sku'],
                'second_product_name' => $r['second_product_name'],
                'transition_count' => (int) $r['transition_count'],
            ], $rows),
        ]);
    }

    private function entryProductsByLtv(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');

        $sql = "SELECT
                    first_sku,
                    first_product_name,
                    COUNT(DISTINCT customer_email) as customer_count,
                    ROUND(AVG(customer_ltv), 2) as avg_customer_ltv,
                    ROUND(SUM(customer_ltv), 2) as total_ltv
                FROM (
                    SELECT
                        first_orders.customer_email,
                        fi.sku as first_sku,
                        fi.name as first_product_name,
                        ltv.customer_ltv
                    FROM (
                        SELECT customer_email, MIN(entity_id) as first_order_id
                        FROM {$so}
                        WHERE customer_email IS NOT NULL AND status != 'canceled'
                        GROUP BY customer_email
                    ) first_orders
                    JOIN {$soi} fi ON fi.order_id = first_orders.first_order_id AND fi.parent_item_id IS NULL
                    JOIN (
                        SELECT customer_email, SUM(grand_total) as customer_ltv
                        FROM {$so}
                        WHERE customer_email IS NOT NULL AND status != 'canceled'
                        GROUP BY customer_email
                    ) ltv ON ltv.customer_email = first_orders.customer_email
                ) entry_data
                GROUP BY first_sku, first_product_name
                ORDER BY avg_customer_ltv DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'entry_products_by_ltv',
            'rows' => array_map(fn(array $r) => [
                'first_sku' => $r['first_sku'],
                'first_product_name' => $r['first_product_name'],
                'customer_count' => (int) $r['customer_count'],
                'avg_customer_ltv' => round((float) $r['avg_customer_ltv'], 2),
                'total_ltv' => round((float) $r['total_ltv'], 2),
            ], $rows),
        ]);
    }

    private function commonSequences(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');

        $sql = "SELECT
                    CONCAT(i1.sku, ' → ', i2.sku) as sequence,
                    i1.name as product_a_name,
                    i2.name as product_b_name,
                    COUNT(*) as occurrence_count
                FROM {$soi} i1
                JOIN {$so} o1 ON o1.entity_id = i1.order_id
                JOIN {$so} o2 ON o2.customer_email = o1.customer_email
                    AND o2.created_at > o1.created_at
                    AND o2.status != 'canceled'
                JOIN {$soi} i2 ON i2.order_id = o2.entity_id AND i2.parent_item_id IS NULL
                WHERE i1.parent_item_id IS NULL
                    AND o1.customer_email IS NOT NULL
                    AND o1.status != 'canceled'
                GROUP BY i1.sku, i1.name, i2.sku, i2.name
                ORDER BY occurrence_count DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'common_sequences',
            'rows' => array_map(fn(array $r) => [
                'sequence' => $r['sequence'],
                'product_a_name' => $r['product_a_name'],
                'product_b_name' => $r['product_b_name'],
                'occurrence_count' => (int) $r['occurrence_count'],
            ], $rows),
        ]);
    }

    private function repeatProductRate(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');

        $sql = "SELECT
                    sku,
                    product_name,
                    repeat_orders,
                    total_orders_containing_sku,
                    ROUND(repeat_orders / total_orders_containing_sku * 100, 2) as repeat_rate_pct
                FROM (
                    SELECT
                        i2.sku,
                        i2.name as product_name,
                        COUNT(*) as repeat_orders,
                        (
                            SELECT COUNT(DISTINCT soi_all.order_id)
                            FROM {$soi} soi_all
                            WHERE soi_all.sku = i2.sku AND soi_all.parent_item_id IS NULL
                        ) as total_orders_containing_sku
                    FROM {$soi} i1
                    JOIN {$so} o1 ON o1.entity_id = i1.order_id
                    JOIN {$so} o2 ON o2.customer_email = o1.customer_email
                        AND o2.created_at > o1.created_at
                        AND o2.status != 'canceled'
                    JOIN {$soi} i2 ON i2.order_id = o2.entity_id
                        AND i2.sku = i1.sku
                        AND i2.parent_item_id IS NULL
                    WHERE i1.parent_item_id IS NULL
                        AND o1.customer_email IS NOT NULL
                        AND o1.status != 'canceled'
                    GROUP BY i2.sku, i2.name
                ) repeat_data
                WHERE total_orders_containing_sku > 0
                ORDER BY repeat_rate_pct DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'repeat_product_rate',
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'product_name' => $r['product_name'],
                'repeat_orders' => (int) $r['repeat_orders'],
                'total_orders_containing_sku' => (int) $r['total_orders_containing_sku'],
                'repeat_rate_pct' => round((float) $r['repeat_rate_pct'], 2),
            ], $rows),
        ]);
    }
}
