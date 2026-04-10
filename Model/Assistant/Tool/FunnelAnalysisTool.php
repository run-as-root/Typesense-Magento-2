<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class FunnelAnalysisTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'funnel_analysis';
    }

    public function getDescription(): string
    {
        return 'Analyze the shopping funnel from cart creation to order placement. Returns cart totals, conversion rates, abandonment rate, average time to convert, and the top abandoned products. Optionally filter by date range.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Start date filter (YYYY-MM-DD). Optional.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'End date filter (YYYY-MM-DD). Optional.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): string
    {
        $dateFrom = $arguments['date_from'] ?? null;
        $dateTo = $arguments['date_to'] ?? null;

        if ($dateFrom !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            return json_encode(['error' => 'Invalid date_from format: expected YYYY-MM-DD.']);
        }
        if ($dateTo !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return json_encode(['error' => 'Invalid date_to format: expected YYYY-MM-DD.']);
        }

        try {
            $connection = $this->resource->getConnection();
            $quoteTable = $this->resource->getTableName('quote');
            $quoteItemTable = $this->resource->getTableName('quote_item');
            $orderTable = $this->resource->getTableName('sales_order');

            $dateParams = [];
            $quoteDateWhere = '';
            $orderDateWhere = '';
            if ($dateFrom !== null) {
                $quoteDateWhere .= ' AND q.created_at >= ?';
                $orderDateWhere .= ' AND created_at >= ?';
                $dateParams[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== null) {
                $quoteDateWhere .= ' AND q.created_at <= ?';
                $orderDateWhere .= ' AND created_at <= ?';
                $dateParams[] = $dateTo . ' 23:59:59';
            }

            // Total quotes and quotes with items
            $quoteSql = "SELECT
                    COUNT(*) as total_quotes,
                    SUM(CASE WHEN items_count > 0 THEN 1 ELSE 0 END) as quotes_with_items,
                    AVG(CASE WHEN grand_total > 0 AND is_active = 1 THEN grand_total ELSE NULL END) as avg_abandoned_cart_value
                FROM {$quoteTable} q
                WHERE 1=1 {$quoteDateWhere}";

            $quoteRow = $connection->fetchRow($quoteSql, $dateParams);

            // Orders placed
            $orderDateParams = $dateFrom !== null || $dateTo !== null ? $dateParams : [];
            $orderSql = "SELECT COUNT(*) as orders_placed FROM {$orderTable} WHERE 1=1 {$orderDateWhere}";
            $ordersPlaced = (int) $connection->fetchOne($orderSql, $orderDateParams);

            // Avg time to convert (quote created_at to order created_at) via reserved_order_id
            $convertTimeSql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, q.created_at, so.created_at) / 60.0) as avg_hours
                FROM {$quoteTable} q
                JOIN {$orderTable} so ON so.quote_id = q.entity_id
                WHERE q.is_active = 0 {$quoteDateWhere}";

            $avgConvertHours = (float) $connection->fetchOne($convertTimeSql, $dateParams);

            $totalQuotes = (int) ($quoteRow['total_quotes'] ?? 0);
            $quotesWithItems = (int) ($quoteRow['quotes_with_items'] ?? 0);
            $avgAbandonedValue = round((float) ($quoteRow['avg_abandoned_cart_value'] ?? 0), 2);

            $abandonmentRate = $quotesWithItems > 0
                ? round((1 - ($ordersPlaced / $quotesWithItems)) * 100, 2)
                : null;

            // Top 5 most abandoned products (in active quotes with items, no corresponding order)
            $abandonedSql = "SELECT qi.name, qi.sku, COUNT(*) as abandoned_count
                FROM {$quoteItemTable} qi
                JOIN {$quoteTable} q ON q.entity_id = qi.quote_id
                WHERE q.is_active = 1 AND q.items_count > 0 AND qi.parent_item_id IS NULL
                GROUP BY qi.sku, qi.name
                ORDER BY abandoned_count DESC
                LIMIT 5";

            $abandonedProducts = $connection->fetchAll($abandonedSql);

            return json_encode([
                'total_quotes' => $totalQuotes,
                'quotes_with_items' => $quotesWithItems,
                'orders_placed' => $ordersPlaced,
                'abandonment_rate_pct' => $abandonmentRate,
                'avg_abandoned_cart_value' => $avgAbandonedValue,
                'avg_time_to_convert_hours' => round($avgConvertHours, 2),
                'most_abandoned_products' => array_map(fn(array $r) => [
                    'name' => $r['name'],
                    'sku' => $r['sku'],
                    'abandoned_count' => (int) $r['abandoned_count'],
                ], $abandonedProducts),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }
}
