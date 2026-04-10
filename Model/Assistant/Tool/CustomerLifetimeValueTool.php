<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class CustomerLifetimeValueTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'customer_lifetime_value';
    }

    public function getDescription(): string
    {
        return 'Analyze customer lifetime value (LTV). Supports top customers by total spend, LTV broken down by first purchased product, LTV by acquisition month cohort, overall average LTV, and a projected LTV calculation.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['top_customers', 'by_first_product', 'by_acquisition_month', 'average_ltv', 'projected_ltv'],
                    'description' => 'The LTV analysis to perform.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10). Not applicable to average_ltv and projected_ltv.',
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

        $validAggregations = ['top_customers', 'by_first_product', 'by_acquisition_month', 'average_ltv', 'projected_ltv'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'top_customers' => $this->topCustomers($limit),
                'by_first_product' => $this->byFirstProduct($limit),
                'by_acquisition_month' => $this->byAcquisitionMonth($limit),
                'average_ltv' => $this->averageLtv(),
                'projected_ltv' => $this->projectedLtv(),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function topCustomers(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT customer_id, customer_email, customer_firstname, customer_lastname,
                    SUM(grand_total) as total_spend,
                    COUNT(*) as order_count,
                    AVG(grand_total) as avg_order_value,
                    MIN(created_at) as first_order_date,
                    MAX(created_at) as last_order_date
                FROM {$so}
                WHERE customer_id IS NOT NULL AND status != 'canceled'
                GROUP BY customer_id, customer_email, customer_firstname, customer_lastname
                ORDER BY total_spend DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'top_customers',
            'rows' => array_map(fn(array $r) => [
                'customer_id' => (int) $r['customer_id'],
                'email' => $r['customer_email'],
                'name' => trim($r['customer_firstname'] . ' ' . $r['customer_lastname']),
                'total_spend' => round((float) $r['total_spend'], 2),
                'order_count' => (int) $r['order_count'],
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
                'first_order_date' => $r['first_order_date'],
                'last_order_date' => $r['last_order_date'],
            ], $rows),
        ]);
    }

    private function byFirstProduct(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soi = $this->resource->getTableName('sales_order_item');

        // Find each customer's first order, then their first item in that order,
        // then group by product and calculate average LTV of those customers.
        $sql = "SELECT fp.first_product_name, fp.first_product_sku,
                    COUNT(DISTINCT ltv.customer_id) as customer_count,
                    AVG(ltv.total_spend) as avg_ltv,
                    SUM(ltv.total_spend) as total_revenue
                FROM (
                    SELECT customer_id, SUM(grand_total) as total_spend
                    FROM {$so}
                    WHERE customer_id IS NOT NULL AND status != 'canceled'
                    GROUP BY customer_id
                ) ltv
                JOIN (
                    SELECT so.customer_id,
                        soi.name as first_product_name,
                        soi.sku as first_product_sku
                    FROM {$so} so
                    JOIN {$soi} soi ON soi.order_id = so.entity_id AND soi.parent_item_id IS NULL
                    WHERE so.customer_id IS NOT NULL
                    AND so.entity_id = (
                        SELECT entity_id FROM {$so} so2
                        WHERE so2.customer_id = so.customer_id
                        ORDER BY so2.created_at ASC LIMIT 1
                    )
                    AND soi.item_id = (
                        SELECT item_id FROM {$soi} soi2
                        WHERE soi2.order_id = so.entity_id AND soi2.parent_item_id IS NULL
                        ORDER BY soi2.item_id ASC LIMIT 1
                    )
                ) fp ON fp.customer_id = ltv.customer_id
                GROUP BY fp.first_product_sku, fp.first_product_name
                ORDER BY avg_ltv DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'by_first_product',
            'rows' => array_map(fn(array $r) => [
                'first_product_name' => $r['first_product_name'],
                'first_product_sku' => $r['first_product_sku'],
                'customer_count' => (int) $r['customer_count'],
                'avg_ltv' => round((float) $r['avg_ltv'], 2),
                'total_revenue' => round((float) $r['total_revenue'], 2),
            ], $rows),
        ]);
    }

    private function byAcquisitionMonth(int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT cohort.acquisition_month,
                    COUNT(DISTINCT cohort.customer_id) as customers,
                    AVG(ltv.total_spend) as avg_ltv,
                    SUM(ltv.total_spend) as cohort_revenue
                FROM (
                    SELECT customer_id, DATE_FORMAT(MIN(created_at), '%Y-%m') as acquisition_month
                    FROM {$so}
                    WHERE customer_id IS NOT NULL AND status != 'canceled'
                    GROUP BY customer_id
                ) cohort
                JOIN (
                    SELECT customer_id, SUM(grand_total) as total_spend
                    FROM {$so}
                    WHERE customer_id IS NOT NULL AND status != 'canceled'
                    GROUP BY customer_id
                ) ltv ON ltv.customer_id = cohort.customer_id
                GROUP BY cohort.acquisition_month
                ORDER BY cohort.acquisition_month DESC
                LIMIT ?";

        $rows = $connection->fetchAll($sql, [$limit]);

        return json_encode([
            'aggregation' => 'by_acquisition_month',
            'rows' => array_map(fn(array $r) => [
                'acquisition_month' => $r['acquisition_month'],
                'customers' => (int) $r['customers'],
                'avg_ltv' => round((float) $r['avg_ltv'], 2),
                'cohort_revenue' => round((float) $r['cohort_revenue'], 2),
            ], $rows),
        ]);
    }

    private function averageLtv(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    COUNT(DISTINCT customer_id) as total_customers,
                    AVG(customer_spend) as avg_ltv,
                    MIN(customer_spend) as min_ltv,
                    MAX(customer_spend) as max_ltv
                FROM (
                    SELECT customer_id, SUM(grand_total) as customer_spend
                    FROM {$so}
                    WHERE customer_id IS NOT NULL AND status != 'canceled'
                    GROUP BY customer_id
                ) customer_totals";

        $row = $connection->fetchRow($sql);

        return json_encode([
            'aggregation' => 'average_ltv',
            'total_customers' => (int) ($row['total_customers'] ?? 0),
            'avg_ltv' => round((float) ($row['avg_ltv'] ?? 0), 2),
            'min_ltv' => round((float) ($row['min_ltv'] ?? 0), 2),
            'max_ltv' => round((float) ($row['max_ltv'] ?? 0), 2),
        ]);
    }

    private function projectedLtv(): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        // Avg order value across all non-cancelled orders
        $avgOrderValue = (float) $connection->fetchOne(
            "SELECT COALESCE(AVG(grand_total), 0) FROM {$so} WHERE status != 'canceled' AND grand_total > 0"
        );

        // Avg orders per customer per year
        // Use time span from first order to now to estimate yearly rate
        $customerStats = $connection->fetchRow(
            "SELECT
                COUNT(*) as total_orders,
                COUNT(DISTINCT customer_id) as total_customers,
                DATEDIFF(NOW(), MIN(created_at)) / 365.0 as years_of_data
            FROM {$so}
            WHERE customer_id IS NOT NULL AND status != 'canceled'"
        );

        $totalOrders = (int) ($customerStats['total_orders'] ?? 0);
        $totalCustomers = (int) ($customerStats['total_customers'] ?? 0);
        $yearsOfData = (float) ($customerStats['years_of_data'] ?? 1);

        if ($totalCustomers === 0 || $yearsOfData <= 0) {
            return json_encode(['error' => 'Not enough order data to compute projected LTV.']);
        }

        $avgOrdersPerYear = ($totalOrders / $totalCustomers) / max($yearsOfData, 1);

        // Avg customer lifespan: time between first and last order per customer, in years
        $avgLifespanRow = $connection->fetchRow(
            "SELECT AVG(DATEDIFF(last_order, first_order) / 365.0) as avg_lifespan_years
            FROM (
                SELECT customer_id, MIN(created_at) as first_order, MAX(created_at) as last_order
                FROM {$so}
                WHERE customer_id IS NOT NULL AND status != 'canceled'
                GROUP BY customer_id
                HAVING COUNT(*) > 1
            ) customer_spans"
        );

        $avgLifespanYears = (float) ($avgLifespanRow['avg_lifespan_years'] ?? 1);
        if ($avgLifespanYears <= 0) {
            $avgLifespanYears = 1.0;
        }

        $projectedLtv = $avgOrdersPerYear * $avgOrderValue * $avgLifespanYears;

        return json_encode([
            'aggregation' => 'projected_ltv',
            'avg_order_value' => round($avgOrderValue, 2),
            'avg_orders_per_year' => round($avgOrdersPerYear, 2),
            'avg_customer_lifespan_years' => round($avgLifespanYears, 2),
            'projected_ltv' => round($projectedLtv, 2),
            'note' => 'Projected LTV = avg_orders_per_year × avg_order_value × avg_customer_lifespan_years',
        ]);
    }
}
