<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class DetectAnomaliesTool implements ToolInterface
{
    private const STATUS_NORMAL = 'normal';
    private const STATUS_WARNING = 'warning';
    private const STATUS_CRITICAL = 'critical';

    private const Z_SCORE_WARNING = 1.5;
    private const Z_SCORE_CRITICAL = 2.5;

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'detect_anomalies';
    }

    public function getDescription(): string
    {
        return 'Detect anomalies in business metrics by comparing the current period against historical averages using z-score analysis. Returns a status of normal, warning, or critical.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric' => [
                    'type' => 'string',
                    'enum' => ['revenue', 'order_count', 'avg_order_value', 'new_customers'],
                    'description' => 'The metric to analyze for anomalies.',
                ],
                'compare_window' => [
                    'type' => 'string',
                    'enum' => ['today_vs_avg', 'this_week_vs_avg', 'this_month_vs_avg'],
                    'description' => 'The comparison window: today, this week, or this month vs historical average.',
                ],
                'lookback_periods' => [
                    'type' => 'integer',
                    'description' => 'Number of historical periods to use as baseline (default: 4).',
                    'default' => 4,
                ],
            ],
            'required' => ['metric', 'compare_window'],
        ];
    }

    public function execute(array $arguments): string
    {
        $metric = $arguments['metric'] ?? '';
        $compareWindow = $arguments['compare_window'] ?? '';
        $lookbackPeriods = max(2, (int) ($arguments['lookback_periods'] ?? 4));

        $validMetrics = ['revenue', 'order_count', 'avg_order_value', 'new_customers'];
        if (!in_array($metric, $validMetrics, true)) {
            return json_encode(['error' => 'Invalid metric: ' . $metric]);
        }

        $validWindows = ['today_vs_avg', 'this_week_vs_avg', 'this_month_vs_avg'];
        if (!in_array($compareWindow, $validWindows, true)) {
            return json_encode(['error' => 'Invalid compare_window: ' . $compareWindow]);
        }

        try {
            $currentValue = $this->fetchCurrentPeriodValue($metric, $compareWindow);
            $historicalValues = $this->fetchHistoricalValues($metric, $compareWindow, $lookbackPeriods);

            if (count($historicalValues) < 2) {
                return json_encode([
                    'metric' => $metric,
                    'compare_window' => $compareWindow,
                    'current_value' => round($currentValue, 2),
                    'status' => self::STATUS_NORMAL,
                    'message' => 'Insufficient historical data for anomaly detection.',
                    'historical_values' => $historicalValues,
                ]);
            }

            $mean = $this->calculateMean($historicalValues);
            $stddev = $this->calculateStddev($historicalValues, $mean);
            $zScore = $stddev > 0 ? ($currentValue - $mean) / $stddev : 0.0;
            $status = $this->classifyStatus(abs($zScore));

            return json_encode([
                'metric' => $metric,
                'compare_window' => $compareWindow,
                'current_value' => round($currentValue, 2),
                'historical_mean' => round($mean, 2),
                'historical_stddev' => round($stddev, 2),
                'z_score' => round($zScore, 3),
                'status' => $status,
                'lookback_periods' => count($historicalValues),
                'historical_values' => array_map(fn (float $v) => round($v, 2), $historicalValues),
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function fetchCurrentPeriodValue(string $metric, string $compareWindow): float
    {
        $connection = $this->resource->getConnection();
        [$table, $metricExpr] = $this->getTableAndExpr($metric);
        $whereClause = $this->getCurrentPeriodWhere($compareWindow);

        $sql = "SELECT COALESCE({$metricExpr}, 0) FROM {$table} WHERE {$whereClause}";

        return (float) $connection->fetchOne($sql);
    }

    /**
     * @return float[]
     */
    private function fetchHistoricalValues(string $metric, string $compareWindow, int $periods): array
    {
        $connection = $this->resource->getConnection();
        [$table, $metricExpr, $groupExpr, $havingClause] = $this->getHistoricalQueryParts($metric, $compareWindow, $periods);

        $sql = "SELECT COALESCE({$metricExpr}, 0) as value
                FROM {$table}
                WHERE {$havingClause}
                GROUP BY {$groupExpr}
                ORDER BY {$groupExpr} DESC
                LIMIT {$periods}";

        $rows = $connection->fetchAll($sql);

        return array_map(fn (array $row) => (float) $row['value'], $rows);
    }

    private function getCurrentPeriodWhere(string $compareWindow): string
    {
        return match ($compareWindow) {
            'today_vs_avg' => 'DATE(created_at) = CURDATE()',
            'this_week_vs_avg' => 'YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)',
            'this_month_vs_avg' => "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            default => throw new \InvalidArgumentException('Invalid compare_window: ' . $compareWindow),
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function getHistoricalQueryParts(string $metric, string $compareWindow, int $periods): array
    {
        [$table, $metricExpr] = $this->getTableAndExpr($metric);

        [$groupExpr, $whereClause] = match ($compareWindow) {
            'today_vs_avg' => [
                'DATE(created_at)',
                "DATE(created_at) < CURDATE() AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$periods} WEEK) AND DAYOFWEEK(created_at) = DAYOFWEEK(CURDATE())",
            ],
            'this_week_vs_avg' => [
                'YEARWEEK(created_at, 1)',
                "YEARWEEK(created_at, 1) < YEARWEEK(CURDATE(), 1) AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$periods} WEEK)",
            ],
            'this_month_vs_avg' => [
                "DATE_FORMAT(created_at, '%Y-%m')",
                "DATE_FORMAT(created_at, '%Y-%m') < DATE_FORMAT(CURDATE(), '%Y-%m') AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$periods} MONTH)",
            ],
            default => throw new \InvalidArgumentException('Invalid compare_window: ' . $compareWindow),
        };

        return [$table, $metricExpr, $groupExpr, $whereClause];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getTableAndExpr(string $metric): array
    {
        $orderTable = $this->resource->getTableName('sales_order');
        $customerTable = $this->resource->getTableName('customer_entity');

        return match ($metric) {
            'revenue' => [$orderTable, 'SUM(grand_total)'],
            'order_count' => [$orderTable, 'COUNT(*)'],
            'avg_order_value' => [$orderTable, 'AVG(grand_total)'],
            'new_customers' => [$customerTable, 'COUNT(*)'],
            default => throw new \InvalidArgumentException('Unsupported metric: ' . $metric),
        };
    }

    /**
     * @param float[] $values
     */
    public function calculateMean(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Calculate population standard deviation.
     * @param float[] $values
     */
    public function calculateStddev(array $values, float $mean): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $sumSquaredDiffs = array_reduce($values, fn (float $carry, float $v) => $carry + (($v - $mean) ** 2), 0.0);

        return sqrt($sumSquaredDiffs / count($values));
    }

    /**
     * Calculate z-score for the current value against historical baseline.
     */
    public function calculateZScore(float $currentValue, float $mean, float $stddev): float
    {
        if ($stddev == 0) {
            return 0.0;
        }

        return ($currentValue - $mean) / $stddev;
    }

    private function classifyStatus(float $absZScore): string
    {
        if ($absZScore >= self::Z_SCORE_CRITICAL) {
            return self::STATUS_CRITICAL;
        }

        if ($absZScore >= self::Z_SCORE_WARNING) {
            return self::STATUS_WARNING;
        }

        return self::STATUS_NORMAL;
    }
}
