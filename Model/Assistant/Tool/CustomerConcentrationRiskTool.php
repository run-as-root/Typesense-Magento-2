<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class CustomerConcentrationRiskTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'customer_concentration_risk';
    }

    public function getDescription(): string
    {
        return 'Analyze customer revenue concentration risk. Includes Pareto-style analysis (what % of revenue comes from top N% of customers), concentration trend over time, and top customer dependency breakdown.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['pareto_analysis', 'concentration_trend', 'top_customer_dependency'],
                    'description' => 'The type of concentration risk analysis to perform.',
                ],
                'top_percentage' => [
                    'type' => 'integer',
                    'description' => 'Analyze the top N% of customers by revenue (default 10).',
                    'default' => 10,
                ],
            ],
            'required' => ['aggregation'],
        ];
    }

    public function execute(array $arguments): string
    {
        $aggregation = $arguments['aggregation'] ?? '';
        $topPercentage = max(1, min(100, (int) ($arguments['top_percentage'] ?? 10)));

        $validAggregations = ['pareto_analysis', 'concentration_trend', 'top_customer_dependency'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'pareto_analysis' => $this->paretoAnalysis($topPercentage),
                'concentration_trend' => $this->concentrationTrend($topPercentage),
                'top_customer_dependency' => $this->topCustomerDependency($topPercentage),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function paretoAnalysis(int $topPercentage): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        // Get total customer count and total revenue
        $totalSql = "SELECT COUNT(DISTINCT customer_email) as total_customers, SUM(grand_total) as total_revenue
                     FROM {$so}
                     WHERE customer_email IS NOT NULL AND status != 'canceled'";
        $totals = $connection->fetchRow($totalSql);

        $totalCustomers = (int) ($totals['total_customers'] ?? 0);
        $totalRevenue = (float) ($totals['total_revenue'] ?? 0);

        if ($totalCustomers === 0) {
            return json_encode(['aggregation' => 'pareto_analysis', 'message' => 'No customer data available']);
        }

        $topN = max(1, (int) ceil($totalCustomers * $topPercentage / 100));

        $topRevenueSql = "SELECT SUM(customer_revenue) as top_revenue
                          FROM (
                              SELECT customer_email, SUM(grand_total) as customer_revenue
                              FROM {$so}
                              WHERE customer_email IS NOT NULL AND status != 'canceled'
                              GROUP BY customer_email
                              ORDER BY customer_revenue DESC
                              LIMIT :top_n
                          ) top_customers";

        $topResult = $connection->fetchRow($topRevenueSql, ['top_n' => $topN]);
        $topRevenue = (float) ($topResult['top_revenue'] ?? 0);

        return json_encode([
            'aggregation' => 'pareto_analysis',
            'top_percentage' => $topPercentage,
            'total_customers' => $totalCustomers,
            'top_n_customers' => $topN,
            'total_revenue' => round($totalRevenue, 2),
            'top_customers_revenue' => round($topRevenue, 2),
            'top_customers_revenue_pct' => $totalRevenue > 0 ? round($topRevenue / $totalRevenue * 100, 2) : 0,
            'remaining_customers_revenue_pct' => $totalRevenue > 0 ? round((1 - $topRevenue / $totalRevenue) * 100, 2) : 0,
        ]);
    }

    private function concentrationTrend(int $topPercentage): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        // Calculate concentration for each of the last 6 months
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-{$i} months"));
            $monthEnd = date('Y-m-t', strtotime("-{$i} months"));
            $monthLabel = date('Y-m', strtotime("-{$i} months"));

            $totalSql = "SELECT COUNT(DISTINCT customer_email) as total_customers, SUM(grand_total) as total_revenue
                         FROM {$so}
                         WHERE customer_email IS NOT NULL AND status != 'canceled'
                             AND created_at >= :start AND created_at <= :end";
            $totals = $connection->fetchRow($totalSql, ['start' => $monthStart . ' 00:00:00', 'end' => $monthEnd . ' 23:59:59']);

            $totalCustomers = (int) ($totals['total_customers'] ?? 0);
            $totalRevenue = (float) ($totals['total_revenue'] ?? 0);

            if ($totalCustomers < 2) {
                $months[] = ['month' => $monthLabel, 'total_customers' => $totalCustomers, 'concentration_pct' => null, 'note' => 'Insufficient data'];
                continue;
            }

            $topN = max(1, (int) ceil($totalCustomers * $topPercentage / 100));

            $topRevenueSql = "SELECT SUM(customer_revenue) as top_revenue
                              FROM (
                                  SELECT customer_email, SUM(grand_total) as customer_revenue
                                  FROM {$so}
                                  WHERE customer_email IS NOT NULL AND status != 'canceled'
                                      AND created_at >= :start AND created_at <= :end
                                  GROUP BY customer_email
                                  ORDER BY customer_revenue DESC
                                  LIMIT :top_n
                              ) top_customers";
            $topResult = $connection->fetchRow($topRevenueSql, ['start' => $monthStart . ' 00:00:00', 'end' => $monthEnd . ' 23:59:59', 'top_n' => $topN]);
            $topRevenue = (float) ($topResult['top_revenue'] ?? 0);

            $months[] = [
                'month' => $monthLabel,
                'total_customers' => $totalCustomers,
                'top_n_customers' => $topN,
                'total_revenue' => round($totalRevenue, 2),
                'concentration_pct' => $totalRevenue > 0 ? round($topRevenue / $totalRevenue * 100, 2) : 0,
            ];
        }

        // Determine trend direction
        $validMonths = array_filter($months, fn($m) => isset($m['concentration_pct']));
        $trend = 'stable';
        if (count($validMonths) >= 2) {
            $values = array_column(array_values($validMonths), 'concentration_pct');
            $first = $values[0];
            $last = $values[count($values) - 1];
            if ($last > $first + 2) {
                $trend = 'increasing';
            } elseif ($last < $first - 2) {
                $trend = 'decreasing';
            }
        }

        return json_encode([
            'aggregation' => 'concentration_trend',
            'top_percentage' => $topPercentage,
            'trend' => $trend,
            'months' => $months,
        ]);
    }

    private function topCustomerDependency(int $topPercentage): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $totalRevenueSql = "SELECT SUM(grand_total) as total_revenue
                            FROM {$so}
                            WHERE customer_email IS NOT NULL AND status != 'canceled'";
        $totalResult = $connection->fetchRow($totalRevenueSql);
        $totalRevenue = (float) ($totalResult['total_revenue'] ?? 0);

        $totalCustomersSql = "SELECT COUNT(DISTINCT customer_email) as cnt FROM {$so}
                              WHERE customer_email IS NOT NULL AND status != 'canceled'";
        $countResult = $connection->fetchRow($totalCustomersSql);
        $totalCustomers = (int) ($countResult['cnt'] ?? 0);

        $topN = max(1, (int) ceil($totalCustomers * $topPercentage / 100));

        $sql = "SELECT
                    customer_email,
                    customer_firstname,
                    customer_lastname,
                    COUNT(*) as order_count,
                    ROUND(SUM(grand_total), 2) as lifetime_revenue
                FROM {$so}
                WHERE customer_email IS NOT NULL AND status != 'canceled'
                GROUP BY customer_email, customer_firstname, customer_lastname
                ORDER BY lifetime_revenue DESC
                LIMIT :top_n";

        $rows = $connection->fetchAll($sql, ['top_n' => $topN]);

        return json_encode([
            'aggregation' => 'top_customer_dependency',
            'top_percentage' => $topPercentage,
            'total_customers' => $totalCustomers,
            'top_n_customers' => $topN,
            'total_revenue' => round($totalRevenue, 2),
            'rows' => array_map(fn(array $r) => [
                'customer_email' => $r['customer_email'],
                'name' => trim(($r['customer_firstname'] ?? '') . ' ' . ($r['customer_lastname'] ?? '')),
                'order_count' => (int) $r['order_count'],
                'lifetime_revenue' => round((float) $r['lifetime_revenue'], 2),
                'revenue_share_pct' => $totalRevenue > 0 ? round((float) $r['lifetime_revenue'] / $totalRevenue * 100, 2) : 0,
            ], $rows),
        ]);
    }
}
