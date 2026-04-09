<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class CohortAnalysisTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'cohort_analysis';
    }

    public function getDescription(): string
    {
        return 'Analyze customer retention by cohort. Groups customers by their first purchase month or quarter, then tracks how many return to purchase in subsequent periods. Returns a retention matrix.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cohort_by' => [
                    'type' => 'string',
                    'enum' => ['first_purchase_month', 'first_purchase_quarter'],
                    'description' => 'How to group customers into cohorts.',
                ],
                'periods' => [
                    'type' => 'integer',
                    'description' => 'Number of subsequent periods to track after the cohort period (default: 6).',
                    'default' => 6,
                ],
            ],
            'required' => ['cohort_by'],
        ];
    }

    public function execute(array $arguments): string
    {
        $cohortBy = $arguments['cohort_by'] ?? 'first_purchase_month';
        $periods = (int) ($arguments['periods'] ?? 6);

        $validCohortBy = ['first_purchase_month', 'first_purchase_quarter'];
        if (!in_array($cohortBy, $validCohortBy, true)) {
            return json_encode(['error' => 'Invalid cohort_by value: ' . $cohortBy]);
        }

        if ($periods < 1 || $periods > 24) {
            return json_encode(['error' => 'Periods must be between 1 and 24.']);
        }

        try {
            $customerCohorts = $this->fetchCustomerCohorts($cohortBy);

            if (empty($customerCohorts)) {
                return json_encode([
                    'cohort_by' => $cohortBy,
                    'cohorts' => [],
                    'message' => 'No order data found.',
                ]);
            }

            $matrix = $this->buildRetentionMatrix($customerCohorts, $cohortBy, $periods);

            return json_encode([
                'cohort_by' => $cohortBy,
                'periods_tracked' => $periods,
                'cohorts' => $matrix,
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    /**
     * Fetch first purchase cohort per customer.
     * @return array<int, array{customer_id: int, cohort: string, order_period: string}>
     */
    private function fetchCustomerCohorts(string $cohortBy): array
    {
        $connection = $this->resource->getConnection();
        $orderTable = $this->resource->getTableName('sales_order');

        $cohortFormat = $cohortBy === 'first_purchase_quarter'
            ? "CONCAT(YEAR(created_at), '-Q', QUARTER(created_at))"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        // Get every order with customer_id and their cohort (first purchase period)
        $sql = "SELECT
                    so.customer_id,
                    MIN({$cohortFormat}) OVER (PARTITION BY so.customer_id) as cohort,
                    {$cohortFormat} as order_period
                FROM {$orderTable} so
                WHERE so.customer_id IS NOT NULL";

        return $connection->fetchAll($sql);
    }

    /**
     * Build the cohort retention matrix.
     * @param array<int, array<string, mixed>> $customerCohorts
     * @return array<string, array<string, mixed>>
     */
    private function buildRetentionMatrix(array $customerCohorts, string $cohortBy, int $periods): array
    {
        // Group: cohort => period => set of customer_ids
        $cohortPeriodCustomers = [];
        foreach ($customerCohorts as $row) {
            $cohort = (string) $row['cohort'];
            $period = (string) $row['order_period'];
            $customerId = (int) $row['customer_id'];
            $cohortPeriodCustomers[$cohort][$period][$customerId] = true;
        }

        // Sort cohorts chronologically
        ksort($cohortPeriodCustomers);

        $matrix = [];
        foreach ($cohortPeriodCustomers as $cohort => $periodData) {
            // Cohort size = distinct customers who had first purchase in this cohort period
            $cohortCustomers = $periodData[$cohort] ?? [];
            $cohortSize = count($cohortCustomers);

            if ($cohortSize === 0) {
                continue;
            }

            $sortedPeriods = array_keys($periodData);
            sort($sortedPeriods);

            $retentionData = [];
            $periodsAfterCohort = 0;

            foreach ($sortedPeriods as $period) {
                if ($period === $cohort) {
                    $retentionData[] = [
                        'period' => $period,
                        'period_number' => 0,
                        'returning_customers' => $cohortSize,
                        'retention_rate' => 100.0,
                    ];
                    continue;
                }

                if ($periodsAfterCohort >= $periods) {
                    break;
                }

                $periodsAfterCohort++;

                // Only count customers who were in the original cohort
                $returningCustomers = count(array_intersect_key(
                    $periodData[$period] ?? [],
                    $cohortCustomers
                ));

                $retentionData[] = [
                    'period' => $period,
                    'period_number' => $periodsAfterCohort,
                    'returning_customers' => $returningCustomers,
                    'retention_rate' => round($returningCustomers / $cohortSize * 100, 2),
                ];
            }

            $matrix[$cohort] = [
                'cohort' => $cohort,
                'cohort_size' => $cohortSize,
                'periods' => $retentionData,
            ];
        }

        return $matrix;
    }
}
