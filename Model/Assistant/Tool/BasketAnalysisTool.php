<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class BasketAnalysisTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'basket_analysis';
    }

    public function getDescription(): string
    {
        return 'Analyze shopping cart and basket data. Includes abandoned cart summaries, most common abandoned products, cart value distribution, and active cart listings.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['abandoned_carts_summary', 'abandoned_cart_products', 'cart_value_distribution', 'active_carts'],
                    'description' => 'The type of basket analysis to perform.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Not applicable to abandoned_carts_summary or cart_value_distribution.',
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

        $validAggregations = ['abandoned_carts_summary', 'abandoned_cart_products', 'cart_value_distribution', 'active_carts'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'abandoned_carts_summary' => $this->abandonedCartsSummary(),
                'abandoned_cart_products' => $this->abandonedCartProducts($limit),
                'cart_value_distribution' => $this->cartValueDistribution(),
                'active_carts' => $this->activeCarts($limit),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function abandonedCartsSummary(): string
    {
        $connection = $this->resource->getConnection();
        $quote = $this->resource->getTableName('quote');

        $sql = "SELECT
                    COUNT(*) as abandoned_cart_count,
                    ROUND(SUM(grand_total), 2) as total_value,
                    ROUND(AVG(grand_total), 2) as avg_value,
                    ROUND(AVG(TIMESTAMPDIFF(HOUR, updated_at, NOW())), 1) as avg_age_hours,
                    SUM(items_count) as total_items
                FROM {$quote}
                WHERE is_active = 1
                    AND items_count > 0
                    AND customer_email IS NOT NULL";

        $row = $connection->fetchRow($sql);

        return json_encode([
            'aggregation' => 'abandoned_carts_summary',
            'abandoned_cart_count' => (int) ($row['abandoned_cart_count'] ?? 0),
            'total_value' => round((float) ($row['total_value'] ?? 0), 2),
            'avg_value' => round((float) ($row['avg_value'] ?? 0), 2),
            'avg_age_hours' => round((float) ($row['avg_age_hours'] ?? 0), 1),
            'total_items' => (int) ($row['total_items'] ?? 0),
        ]);
    }

    private function abandonedCartProducts(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $quote = $this->resource->getTableName('quote');
        $quoteItem = $this->resource->getTableName('quote_item');

        $sql = "SELECT
                    qi.sku,
                    qi.name,
                    COUNT(DISTINCT q.entity_id) as cart_count,
                    SUM(qi.qty) as total_qty,
                    ROUND(SUM(qi.row_total), 2) as total_value
                FROM {$quote} q
                JOIN {$quoteItem} qi ON qi.quote_id = q.entity_id
                WHERE q.is_active = 1
                    AND q.items_count > 0
                    AND qi.parent_item_id IS NULL
                GROUP BY qi.sku, qi.name
                ORDER BY cart_count DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'abandoned_cart_products',
            'rows' => array_map(fn(array $r) => [
                'sku' => $r['sku'],
                'name' => $r['name'],
                'cart_count' => (int) $r['cart_count'],
                'total_qty' => round((float) $r['total_qty'], 0),
                'total_value' => round((float) $r['total_value'], 2),
            ], $rows),
        ]);
    }

    private function cartValueDistribution(): string
    {
        $connection = $this->resource->getConnection();
        $quote = $this->resource->getTableName('quote');

        $sql = "SELECT
                    SUM(CASE WHEN grand_total < 50 THEN 1 ELSE 0 END) as under_50,
                    SUM(CASE WHEN grand_total >= 50 AND grand_total < 100 THEN 1 ELSE 0 END) as between_50_100,
                    SUM(CASE WHEN grand_total >= 100 AND grand_total < 200 THEN 1 ELSE 0 END) as between_100_200,
                    SUM(CASE WHEN grand_total >= 200 THEN 1 ELSE 0 END) as over_200,
                    COUNT(*) as total_carts
                FROM {$quote}
                WHERE is_active = 1
                    AND items_count > 0";

        $row = $connection->fetchRow($sql);

        $total = max(1, (int) ($row['total_carts'] ?? 1));

        return json_encode([
            'aggregation' => 'cart_value_distribution',
            'total_carts' => $total,
            'buckets' => [
                ['range' => 'Under $50', 'count' => (int) ($row['under_50'] ?? 0), 'pct' => round((int) ($row['under_50'] ?? 0) / $total * 100, 1)],
                ['range' => '$50 - $100', 'count' => (int) ($row['between_50_100'] ?? 0), 'pct' => round((int) ($row['between_50_100'] ?? 0) / $total * 100, 1)],
                ['range' => '$100 - $200', 'count' => (int) ($row['between_100_200'] ?? 0), 'pct' => round((int) ($row['between_100_200'] ?? 0) / $total * 100, 1)],
                ['range' => 'Over $200', 'count' => (int) ($row['over_200'] ?? 0), 'pct' => round((int) ($row['over_200'] ?? 0) / $total * 100, 1)],
            ],
        ]);
    }

    private function activeCarts(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $quote = $this->resource->getTableName('quote');

        $sql = "SELECT
                    entity_id as cart_id,
                    customer_email,
                    items_count,
                    ROUND(grand_total, 2) as grand_total,
                    ROUND(TIMESTAMPDIFF(HOUR, updated_at, NOW()), 1) as age_hours,
                    updated_at
                FROM {$quote}
                WHERE is_active = 1
                    AND items_count > 0
                ORDER BY updated_at DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'aggregation' => 'active_carts',
            'rows' => array_map(fn(array $r) => [
                'cart_id' => (int) $r['cart_id'],
                'customer_email' => $r['customer_email'],
                'items_count' => (int) $r['items_count'],
                'grand_total' => round((float) $r['grand_total'], 2),
                'age_hours' => round((float) $r['age_hours'], 1),
                'updated_at' => $r['updated_at'],
            ], $rows),
        ]);
    }
}
