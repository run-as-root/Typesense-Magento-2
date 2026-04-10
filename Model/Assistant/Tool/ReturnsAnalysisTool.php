<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class ReturnsAnalysisTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'returns_analysis';
    }

    public function getDescription(): string
    {
        return 'Analyze product returns and refunds using credit memo data. Supports return rate by product, return rate by category, total refund summary, and refund trend over time.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['return_rate_by_product', 'return_rate_by_category', 'total_refunds', 'refund_trend'],
                    'description' => 'The returns analysis to perform.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Not applicable to total_refunds.',
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

        $validAggregations = ['return_rate_by_product', 'return_rate_by_category', 'total_refunds', 'refund_trend'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'return_rate_by_product' => $this->returnRateByProduct($limit),
                'return_rate_by_category' => $this->returnRateByCategory($limit),
                'total_refunds' => $this->totalRefunds(),
                'refund_trend' => $this->refundTrend($limit),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function returnRateByProduct(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $scmi = $this->resource->getTableName('sales_creditmemo_item');
        $scm = $this->resource->getTableName('sales_creditmemo');
        $soi = $this->resource->getTableName('sales_order_item');

        $sql = "SELECT refunds.sku, refunds.name,
                    refunds.qty_refunded,
                    COALESCE(sales.qty_sold, 0) as qty_sold,
                    refunds.total_refunded,
                    CASE WHEN COALESCE(sales.qty_sold, 0) > 0
                        THEN ROUND(refunds.qty_refunded / sales.qty_sold * 100, 2)
                        ELSE NULL END as return_rate_pct
                FROM (
                    SELECT scmi.sku, scmi.name,
                        SUM(scmi.qty) as qty_refunded,
                        SUM(scmi.row_total) as total_refunded
                    FROM {$scmi} scmi
                    JOIN {$scm} scm ON scm.entity_id = scmi.parent_id
                    WHERE scmi.parent_item_id IS NULL
                    GROUP BY scmi.sku, scmi.name
                ) refunds
                LEFT JOIN (
                    SELECT sku, SUM(qty_ordered) as qty_sold
                    FROM {$soi}
                    WHERE parent_item_id IS NULL
                    GROUP BY sku
                ) sales ON sales.sku = refunds.sku
                ORDER BY return_rate_pct DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'return_rate_by_product',
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'name' => $r['name'],
                'qty_refunded' => round((float) $r['qty_refunded'], 2),
                'qty_sold' => round((float) $r['qty_sold'], 2),
                'total_refunded' => round((float) $r['total_refunded'], 2),
                'return_rate_pct' => $r['return_rate_pct'] !== null ? round((float) $r['return_rate_pct'], 2) : null,
            ], $rows),
        ]);
    }

    private function returnRateByCategory(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $scmi = $this->resource->getTableName('sales_creditmemo_item');
        $scm = $this->resource->getTableName('sales_creditmemo');
        $soi = $this->resource->getTableName('sales_order_item');
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $ccp = $this->resource->getTableName('catalog_category_product');
        $ccev = $this->resource->getTableName('catalog_category_entity_varchar');
        $eavAttr = $this->resource->getTableName('eav_attribute');

        $sql = "SELECT cv.value as category_name,
                    SUM(scmi.qty) as qty_refunded,
                    SUM(scmi.row_total) as total_refunded,
                    COALESCE(sold.qty_sold, 0) as qty_sold,
                    CASE WHEN COALESCE(sold.qty_sold, 0) > 0
                        THEN ROUND(SUM(scmi.qty) / sold.qty_sold * 100, 2)
                        ELSE NULL END as return_rate_pct
                FROM {$scmi} scmi
                JOIN {$scm} scm ON scm.entity_id = scmi.parent_id
                JOIN {$cpe} cpe ON cpe.sku = scmi.sku
                JOIN {$ccp} ccp ON ccp.product_id = cpe.entity_id
                JOIN {$ccev} cv ON cv.entity_id = ccp.category_id
                    AND cv.attribute_id = (SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = 'name' AND entity_type_id = 3)
                LEFT JOIN (
                    SELECT ccp2.category_id, SUM(soi2.qty_ordered) as qty_sold
                    FROM {$soi} soi2
                    JOIN {$cpe} cpe2 ON cpe2.sku = soi2.sku
                    JOIN {$ccp} ccp2 ON ccp2.product_id = cpe2.entity_id
                    WHERE soi2.parent_item_id IS NULL
                    GROUP BY ccp2.category_id
                ) sold ON sold.category_id = ccp.category_id
                WHERE scmi.parent_item_id IS NULL AND cv.value IS NOT NULL
                GROUP BY ccp.category_id, cv.value, sold.qty_sold
                ORDER BY return_rate_pct DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'return_rate_by_category',
            'rows' => array_map(fn(array $r) => [
                'category_name' => $r['category_name'],
                'qty_refunded' => round((float) $r['qty_refunded'], 2),
                'qty_sold' => round((float) $r['qty_sold'], 2),
                'total_refunded' => round((float) $r['total_refunded'], 2),
                'return_rate_pct' => $r['return_rate_pct'] !== null ? round((float) $r['return_rate_pct'], 2) : null,
            ], $rows),
        ]);
    }

    private function totalRefunds(): string
    {
        $connection = $this->resource->getConnection();
        $scm = $this->resource->getTableName('sales_creditmemo');

        $sql = "SELECT
                    COUNT(*) as total_creditmemos,
                    SUM(grand_total) as total_refunded,
                    SUM(adjustment_positive) as total_adjustment_positive,
                    SUM(adjustment_negative) as total_adjustment_negative,
                    AVG(grand_total) as avg_refund_amount,
                    MIN(created_at) as earliest_refund,
                    MAX(created_at) as latest_refund
                FROM {$scm}";

        $row = $connection->fetchRow($sql);

        return json_encode([
            'aggregation' => 'total_refunds',
            'total_creditmemos' => (int) ($row['total_creditmemos'] ?? 0),
            'total_refunded' => round((float) ($row['total_refunded'] ?? 0), 2),
            'total_adjustment_positive' => round((float) ($row['total_adjustment_positive'] ?? 0), 2),
            'total_adjustment_negative' => round((float) ($row['total_adjustment_negative'] ?? 0), 2),
            'avg_refund_amount' => round((float) ($row['avg_refund_amount'] ?? 0), 2),
            'earliest_refund' => $row['earliest_refund'] ?? null,
            'latest_refund' => $row['latest_refund'] ?? null,
        ]);
    }

    private function refundTrend(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $scm = $this->resource->getTableName('sales_creditmemo');

        $sql = "SELECT
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as refund_count,
                    SUM(grand_total) as total_refunded,
                    AVG(grand_total) as avg_refund_amount
                FROM {$scm}
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'refund_trend',
            'rows' => array_map(fn(array $r) => [
                'month' => $r['month'],
                'refund_count' => (int) $r['refund_count'],
                'total_refunded' => round((float) $r['total_refunded'], 2),
                'avg_refund_amount' => round((float) $r['avg_refund_amount'], 2),
            ], $rows),
        ]);
    }
}
