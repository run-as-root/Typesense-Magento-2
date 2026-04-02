<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class QueryOrdersTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'query_orders';
    }

    public function getDescription(): string
    {
        return 'Run SQL aggregations on order data. Supports revenue totals, revenue by country, top customers, order count by status, average order value, and orders by month. Apply optional filters for country, status, and date range.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => [
                        'total_revenue',
                        'revenue_by_country',
                        'revenue_by_customer',
                        'order_count_by_status',
                        'avg_order_value',
                        'top_customers_by_revenue',
                        'orders_by_month',
                    ],
                    'description' => 'The type of aggregation to run',
                ],
                'country' => [
                    'type' => 'string',
                    'description' => 'Filter by shipping country code (e.g. "DE", "US")',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by order status (e.g. "complete", "pending")',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Filter orders created after this date (YYYY-MM-DD)',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Filter orders created before this date (YYYY-MM-DD)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (default 10)',
                ],
            ],
            'required' => ['aggregation'],
        ];
    }

    public function execute(array $arguments): string
    {
        $aggregation = $arguments['aggregation'] ?? '';
        $country = $arguments['country'] ?? '';
        $status = $arguments['status'] ?? '';
        $dateFrom = $arguments['date_from'] ?? '';
        $dateTo = $arguments['date_to'] ?? '';
        $limit = min((int) ($arguments['limit'] ?? 10), 100);

        $connection = $this->resource->getConnection();
        $salesOrderTable = $this->resource->getTableName('sales_order');
        $salesOrderAddressTable = $this->resource->getTableName('sales_order_address');

        // Build common WHERE clauses and binds
        $whereClauses = [];
        $binds = [];

        if ($status !== '') {
            $whereClauses[] = 'so.status = :status';
            $binds[':status'] = $status;
        }

        if ($dateFrom !== '') {
            $whereClauses[] = 'so.created_at >= :date_from';
            $binds[':date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $whereClauses[] = 'so.created_at <= :date_to';
            $binds[':date_to'] = $dateTo . ' 23:59:59';
        }

        $whereStr = $whereClauses ? ' AND ' . implode(' AND ', $whereClauses) : '';
        $whereBase = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        switch ($aggregation) {
            case 'total_revenue':
                $sql = "SELECT SUM(so.grand_total) as total, so.order_currency_code as currency
                        FROM {$salesOrderTable} so
                        WHERE so.grand_total > 0{$whereStr}
                        GROUP BY so.order_currency_code";
                break;

            case 'revenue_by_country':
                $countryClauses = $whereClauses;
                $countryBinds = $binds;
                if ($country !== '') {
                    $countryClauses[] = 'soa.country_id = :country';
                    $countryBinds[':country'] = $country;
                }
                $countryWhere = $countryClauses ? ' WHERE ' . implode(' AND ', $countryClauses) : '';
                $sql = "SELECT soa.country_id, COUNT(*) as order_count, SUM(so.grand_total) as total_revenue
                        FROM {$salesOrderTable} so
                        JOIN {$salesOrderAddressTable} soa
                            ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping'
                        {$countryWhere}
                        GROUP BY soa.country_id
                        ORDER BY total_revenue DESC
                        LIMIT {$limit}";
                $binds = $countryBinds;
                break;

            case 'revenue_by_customer':
                $sql = "SELECT so.customer_email, so.customer_firstname, so.customer_lastname,
                               SUM(so.grand_total) as total_spent, COUNT(*) as order_count
                        FROM {$salesOrderTable} so
                        WHERE so.customer_id IS NOT NULL{$whereStr}
                        GROUP BY so.customer_id
                        ORDER BY total_spent DESC
                        LIMIT {$limit}";
                break;

            case 'order_count_by_status':
                $sql = "SELECT so.status, COUNT(*) as count
                        FROM {$salesOrderTable} so
                        {$whereBase}
                        GROUP BY so.status
                        ORDER BY count DESC";
                break;

            case 'avg_order_value':
                $sql = "SELECT AVG(so.grand_total) as avg_value, so.order_currency_code
                        FROM {$salesOrderTable} so
                        WHERE so.grand_total > 0{$whereStr}
                        GROUP BY so.order_currency_code";
                break;

            case 'top_customers_by_revenue':
                $sql = "SELECT so.customer_email, so.customer_firstname, so.customer_lastname,
                               SUM(so.grand_total) as total_spent, COUNT(*) as order_count
                        FROM {$salesOrderTable} so
                        WHERE so.customer_id IS NOT NULL{$whereStr}
                        GROUP BY so.customer_id
                        ORDER BY total_spent DESC
                        LIMIT {$limit}";
                break;

            case 'orders_by_month':
                $sql = "SELECT DATE_FORMAT(so.created_at, '%Y-%m') as month,
                               COUNT(*) as order_count, SUM(so.grand_total) as total
                        FROM {$salesOrderTable} so
                        {$whereBase}
                        GROUP BY month
                        ORDER BY month DESC
                        LIMIT {$limit}";
                break;

            default:
                return json_encode(['error' => 'Unknown aggregation: ' . $aggregation]);
        }

        $rows = $connection->fetchAll($sql, $binds);

        return json_encode([
            'aggregation' => $aggregation,
            'rows' => $rows,
            'count' => count($rows),
        ]);
    }
}
