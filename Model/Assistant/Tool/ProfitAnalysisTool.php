<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class ProfitAnalysisTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'profit_analysis';
    }

    public function getDescription(): string
    {
        return 'Analyze product and order profitability using cost data. Supports profit by product, profit by category, profit margin trend over time, and an overall summary.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['profit_by_product', 'profit_by_category', 'profit_margin_trend', 'overall_summary'],
                    'description' => 'The type of profit analysis to perform.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Not applicable to overall_summary.',
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

        $validAggregations = ['profit_by_product', 'profit_by_category', 'profit_margin_trend', 'overall_summary'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'profit_by_product' => $this->profitByProduct($limit),
                'profit_by_category' => $this->profitByCategory($limit),
                'profit_margin_trend' => $this->profitMarginTrend($limit),
                'overall_summary' => $this->overallSummary(),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function profitByProduct(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $soi = $this->resource->getTableName('sales_order_item');
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $cpd = $this->resource->getTableName('catalog_product_entity_decimal');
        $eavAttr = $this->resource->getTableName('eav_attribute');

        $sql = "SELECT soi.name, soi.sku,
                    SUM(soi.qty_ordered) as units_sold,
                    SUM(soi.row_total) as revenue,
                    SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as total_cost,
                    SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as gross_profit,
                    CASE WHEN SUM(soi.row_total) > 0
                        THEN ROUND((SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0))) / SUM(soi.row_total) * 100, 2)
                        ELSE 0 END as margin_pct
                FROM {$soi} soi
                LEFT JOIN {$cpe} cpe ON cpe.sku = soi.sku
                LEFT JOIN {$cpd} cpd ON cpd.entity_id = cpe.entity_id
                    AND cpd.attribute_id = (SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = 'cost' AND entity_type_id = 4)
                WHERE soi.parent_item_id IS NULL
                GROUP BY soi.sku, soi.name
                ORDER BY gross_profit DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'profit_by_product',
            'rows' => array_map(fn(array $r) => [
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units_sold' => (int) $r['units_sold'],
                'revenue' => round((float) $r['revenue'], 2),
                'total_cost' => round((float) $r['total_cost'], 2),
                'gross_profit' => round((float) $r['gross_profit'], 2),
                'margin_pct' => round((float) $r['margin_pct'], 2),
            ], $rows),
        ]);
    }

    private function profitByCategory(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $soi = $this->resource->getTableName('sales_order_item');
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $cpd = $this->resource->getTableName('catalog_product_entity_decimal');
        $eavAttr = $this->resource->getTableName('eav_attribute');
        $ccp = $this->resource->getTableName('catalog_category_product');
        $ccev = $this->resource->getTableName('catalog_category_entity_varchar');

        $sql = "SELECT cv.value as category_name,
                    SUM(soi.qty_ordered) as units_sold,
                    SUM(soi.row_total) as revenue,
                    SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as total_cost,
                    SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as gross_profit,
                    CASE WHEN SUM(soi.row_total) > 0
                        THEN ROUND((SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0))) / SUM(soi.row_total) * 100, 2)
                        ELSE 0 END as margin_pct
                FROM {$soi} soi
                LEFT JOIN {$cpe} cpe ON cpe.sku = soi.sku
                LEFT JOIN {$cpd} cpd ON cpd.entity_id = cpe.entity_id
                    AND cpd.attribute_id = (SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = 'cost' AND entity_type_id = 4)
                LEFT JOIN {$ccp} ccp ON ccp.product_id = cpe.entity_id
                LEFT JOIN {$ccev} cv ON cv.entity_id = ccp.category_id
                    AND cv.attribute_id = (SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = 'name' AND entity_type_id = 3)
                WHERE soi.parent_item_id IS NULL AND cv.value IS NOT NULL
                GROUP BY ccp.category_id, cv.value
                ORDER BY gross_profit DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'profit_by_category',
            'rows' => array_map(fn(array $r) => [
                'category_name' => $r['category_name'],
                'units_sold' => (int) $r['units_sold'],
                'revenue' => round((float) $r['revenue'], 2),
                'total_cost' => round((float) $r['total_cost'], 2),
                'gross_profit' => round((float) $r['gross_profit'], 2),
                'margin_pct' => round((float) $r['margin_pct'], 2),
            ], $rows),
        ]);
    }

    private function profitMarginTrend(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $cpd = $this->resource->getTableName('catalog_product_entity_decimal');
        $eavAttr = $this->resource->getTableName('eav_attribute');

        $sql = "SELECT DATE_FORMAT(so.created_at, '%Y-%m') as month,
                    SUM(soi.row_total) as revenue,
                    SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as total_cost,
                    SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as gross_profit,
                    CASE WHEN SUM(soi.row_total) > 0
                        THEN ROUND((SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0))) / SUM(soi.row_total) * 100, 2)
                        ELSE 0 END as margin_pct
                FROM {$soi} soi
                JOIN {$so} so ON so.entity_id = soi.order_id
                LEFT JOIN {$cpe} cpe ON cpe.sku = soi.sku
                LEFT JOIN {$cpd} cpd ON cpd.entity_id = cpe.entity_id
                    AND cpd.attribute_id = (SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = 'cost' AND entity_type_id = 4)
                WHERE soi.parent_item_id IS NULL AND so.status != 'canceled'
                GROUP BY DATE_FORMAT(so.created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'profit_margin_trend',
            'rows' => array_map(fn(array $r) => [
                'month' => $r['month'],
                'revenue' => round((float) $r['revenue'], 2),
                'total_cost' => round((float) $r['total_cost'], 2),
                'gross_profit' => round((float) $r['gross_profit'], 2),
                'margin_pct' => round((float) $r['margin_pct'], 2),
            ], $rows),
        ]);
    }

    private function overallSummary(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $cpd = $this->resource->getTableName('catalog_product_entity_decimal');
        $eavAttr = $this->resource->getTableName('eav_attribute');

        $sql = "SELECT
                    SUM(soi.row_total) as total_revenue,
                    SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as total_cost,
                    SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0)) as total_profit,
                    CASE WHEN SUM(soi.row_total) > 0
                        THEN ROUND((SUM(soi.row_total) - SUM(soi.qty_ordered * COALESCE(cpd.value, 0))) / SUM(soi.row_total) * 100, 2)
                        ELSE 0 END as avg_margin_pct
                FROM {$soi} soi
                JOIN {$so} so ON so.entity_id = soi.order_id
                LEFT JOIN {$cpe} cpe ON cpe.sku = soi.sku
                LEFT JOIN {$cpd} cpd ON cpd.entity_id = cpe.entity_id
                    AND cpd.attribute_id = (SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = 'cost' AND entity_type_id = 4)
                WHERE soi.parent_item_id IS NULL AND so.status != 'canceled'";

        $row = $connection->fetchRow($sql);

        return json_encode([
            'aggregation' => 'overall_summary',
            'total_revenue' => round((float) ($row['total_revenue'] ?? 0), 2),
            'total_cost' => round((float) ($row['total_cost'] ?? 0), 2),
            'total_profit' => round((float) ($row['total_profit'] ?? 0), 2),
            'avg_margin_pct' => round((float) ($row['avg_margin_pct'] ?? 0), 2),
        ]);
    }
}
