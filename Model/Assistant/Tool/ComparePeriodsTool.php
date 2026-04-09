<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class ComparePeriodsTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'compare_periods';
    }

    public function getDescription(): string
    {
        return 'Compare a business metric between two date periods and calculate the change. Supports revenue, order count, new customers, average order value, and units sold.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric' => [
                    'type' => 'string',
                    'enum' => ['revenue', 'order_count', 'new_customers', 'avg_order_value', 'units_sold'],
                    'description' => 'The metric to compare between periods.',
                ],
                'period_1_start' => [
                    'type' => 'string',
                    'description' => 'Start date of period 1 (YYYY-MM-DD).',
                ],
                'period_1_end' => [
                    'type' => 'string',
                    'description' => 'End date of period 1 (YYYY-MM-DD).',
                ],
                'period_2_start' => [
                    'type' => 'string',
                    'description' => 'Start date of period 2 (YYYY-MM-DD).',
                ],
                'period_2_end' => [
                    'type' => 'string',
                    'description' => 'End date of period 2 (YYYY-MM-DD).',
                ],
            ],
            'required' => ['metric', 'period_1_start', 'period_1_end', 'period_2_start', 'period_2_end'],
        ];
    }

    public function execute(array $arguments): string
    {
        $metric = $arguments['metric'] ?? '';
        $p1Start = $arguments['period_1_start'] ?? '';
        $p1End = $arguments['period_1_end'] ?? '';
        $p2Start = $arguments['period_2_start'] ?? '';
        $p2End = $arguments['period_2_end'] ?? '';

        $validMetrics = ['revenue', 'order_count', 'new_customers', 'avg_order_value', 'units_sold'];
        if (!in_array($metric, $validMetrics, true)) {
            return json_encode(['error' => 'Invalid metric: ' . $metric]);
        }

        foreach (['period_1_start' => $p1Start, 'period_1_end' => $p1End, 'period_2_start' => $p2Start, 'period_2_end' => $p2End] as $name => $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return json_encode(['error' => "Invalid date format for {$name}: expected YYYY-MM-DD."]);
            }
        }

        try {
            $value1 = $this->fetchMetric($metric, $p1Start, $p1End);
            $value2 = $this->fetchMetric($metric, $p2Start, $p2End);

            $absoluteChange = $value2 - $value1;
            $percentageChange = $value1 != 0 ? ($absoluteChange / $value1) * 100 : null;

            $direction = 'flat';
            if ($absoluteChange > 0) {
                $direction = 'up';
            } elseif ($absoluteChange < 0) {
                $direction = 'down';
            }

            return json_encode([
                'metric' => $metric,
                'period_1' => [
                    'start' => $p1Start,
                    'end' => $p1End,
                    'value' => round($value1, 2),
                ],
                'period_2' => [
                    'start' => $p2Start,
                    'end' => $p2End,
                    'value' => round($value2, 2),
                ],
                'absolute_change' => round($absoluteChange, 2),
                'percentage_change' => $percentageChange !== null ? round($percentageChange, 2) : null,
                'direction' => $direction,
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function fetchMetric(string $metric, string $startDate, string $endDate): float
    {
        $connection = $this->resource->getConnection();
        $orderTable = $this->resource->getTableName('sales_order');
        $orderItemTable = $this->resource->getTableName('sales_order_item');
        $customerTable = $this->resource->getTableName('customer_entity');

        // Append end of day to end date so the full day is included
        $endDateTime = $endDate . ' 23:59:59';
        $startDateTime = $startDate . ' 00:00:00';

        return match ($metric) {
            'revenue' => (float) $connection->fetchOne(
                "SELECT COALESCE(SUM(grand_total), 0) FROM {$orderTable} WHERE created_at BETWEEN ? AND ? AND status != 'canceled'",
                [$startDateTime, $endDateTime]
            ),
            'order_count' => (float) $connection->fetchOne(
                "SELECT COUNT(*) FROM {$orderTable} WHERE created_at BETWEEN ? AND ?",
                [$startDateTime, $endDateTime]
            ),
            'new_customers' => (float) $connection->fetchOne(
                "SELECT COUNT(*) FROM {$customerTable} WHERE created_at BETWEEN ? AND ?",
                [$startDateTime, $endDateTime]
            ),
            'avg_order_value' => (float) $connection->fetchOne(
                "SELECT COALESCE(AVG(grand_total), 0) FROM {$orderTable} WHERE created_at BETWEEN ? AND ? AND grand_total > 0",
                [$startDateTime, $endDateTime]
            ),
            'units_sold' => (float) $connection->fetchOne(
                "SELECT COALESCE(SUM(soi.qty_ordered), 0) FROM {$orderTable} so JOIN {$orderItemTable} soi ON soi.order_id = so.entity_id WHERE so.created_at BETWEEN ? AND ?",
                [$startDateTime, $endDateTime]
            ),
            default => throw new \InvalidArgumentException('Unsupported metric: ' . $metric),
        };
    }
}
