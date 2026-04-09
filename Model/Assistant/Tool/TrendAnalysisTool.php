<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class TrendAnalysisTool implements ToolInterface
{
    private const GROWING_THRESHOLD = 5.0;
    private const DECLINING_THRESHOLD = -5.0;
    private const MOVING_AVG_WINDOW = 3;

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'trend_analysis';
    }

    public function getDescription(): string
    {
        return 'Analyze the trend of a metric over time with configurable granularity (day/week/month). Returns a time series, 3-period moving average, overall growth rate, and direction (growing/declining/stable).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric' => [
                    'type' => 'string',
                    'enum' => ['revenue', 'order_count', 'new_customers', 'avg_order_value'],
                    'description' => 'The metric to analyze over time.',
                ],
                'granularity' => [
                    'type' => 'string',
                    'enum' => ['day', 'week', 'month'],
                    'description' => 'Time granularity for grouping data points.',
                ],
                'periods' => [
                    'type' => 'integer',
                    'description' => 'Number of periods to look back (default: 12).',
                    'default' => 12,
                ],
            ],
            'required' => ['metric', 'granularity'],
        ];
    }

    public function execute(array $arguments): string
    {
        $metric = $arguments['metric'] ?? '';
        $granularity = $arguments['granularity'] ?? '';
        $periods = (int) ($arguments['periods'] ?? 12);

        $validMetrics = ['revenue', 'order_count', 'new_customers', 'avg_order_value'];
        if (!in_array($metric, $validMetrics, true)) {
            return json_encode(['error' => 'Invalid metric: ' . $metric]);
        }

        $validGranularities = ['day', 'week', 'month'];
        if (!in_array($granularity, $validGranularities, true)) {
            return json_encode(['error' => 'Invalid granularity: ' . $granularity]);
        }

        if ($periods < 1 || $periods > 365) {
            return json_encode(['error' => 'Periods must be between 1 and 365.']);
        }

        try {
            $series = $this->fetchSeries($metric, $granularity, $periods);

            if (empty($series)) {
                return json_encode([
                    'metric' => $metric,
                    'granularity' => $granularity,
                    'periods' => $periods,
                    'series' => [],
                    'moving_average' => [],
                    'growth_rate' => null,
                    'direction' => 'stable',
                ]);
            }

            $values = array_column($series, 'value');
            $movingAvg = $this->calculateMovingAverage($values, self::MOVING_AVG_WINDOW);
            $growthRate = $this->calculateGrowthRate($values);
            $direction = $this->determineDirection($growthRate);

            // Attach moving average back to series for readability
            $seriesWithMa = [];
            foreach ($series as $i => $point) {
                $seriesWithMa[] = [
                    'period' => $point['period'],
                    'value' => round((float) $point['value'], 2),
                    'moving_avg' => isset($movingAvg[$i]) ? round($movingAvg[$i], 2) : null,
                ];
            }

            return json_encode([
                'metric' => $metric,
                'granularity' => $granularity,
                'periods_returned' => count($series),
                'series' => $seriesWithMa,
                'growth_rate' => $growthRate !== null ? round($growthRate, 2) : null,
                'direction' => $direction,
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array{period: string, value: float}>
     */
    private function fetchSeries(string $metric, string $granularity, int $periods): array
    {
        $connection = $this->resource->getConnection();

        [$table, $metricExpr, $periodExpr, $intervalUnit] = $this->buildQueryParts($metric, $granularity, $periods);

        $sql = "SELECT {$periodExpr} as period, {$metricExpr} as value
                FROM {$table}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$periods} {$intervalUnit})
                GROUP BY period
                ORDER BY period ASC";

        return $connection->fetchAll($sql);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function buildQueryParts(string $metric, string $granularity, int $periods): array
    {
        $orderTable = $this->resource->getTableName('sales_order');
        $customerTable = $this->resource->getTableName('customer_entity');

        $table = $metric === 'new_customers' ? $customerTable : $orderTable;

        $metricExpr = match ($metric) {
            'revenue' => 'COALESCE(SUM(grand_total), 0)',
            'order_count' => 'COUNT(*)',
            'new_customers' => 'COUNT(*)',
            'avg_order_value' => 'COALESCE(AVG(grand_total), 0)',
            default => throw new \InvalidArgumentException('Unsupported metric: ' . $metric),
        };

        [$periodExpr, $intervalUnit] = match ($granularity) {
            'day' => ["DATE(created_at)", 'DAY'],
            'week' => ["DATE_FORMAT(created_at, '%Y-%u')", 'WEEK'],
            'month' => ["DATE_FORMAT(created_at, '%Y-%m')", 'MONTH'],
            default => throw new \InvalidArgumentException('Unsupported granularity: ' . $granularity),
        };

        return [$table, $metricExpr, $periodExpr, $intervalUnit];
    }

    /**
     * Calculate N-period moving average.
     * @param float[] $values
     * @return array<int, float|null>
     */
    public function calculateMovingAverage(array $values, int $window): array
    {
        $result = [];
        $count = count($values);

        for ($i = 0; $i < $count; $i++) {
            if ($i < $window - 1) {
                $result[] = null;
                continue;
            }
            $slice = array_slice($values, $i - $window + 1, $window);
            $result[] = array_sum($slice) / $window;
        }

        return $result;
    }

    /**
     * Calculate overall growth rate from first to last value.
     * @param float[] $values
     */
    public function calculateGrowthRate(array $values): ?float
    {
        if (count($values) < 2) {
            return null;
        }

        $first = (float) $values[0];
        $last = (float) $values[count($values) - 1];

        if ($first == 0) {
            return null;
        }

        return ($last - $first) / $first * 100;
    }

    public function determineDirection(?float $growthRate): string
    {
        if ($growthRate === null) {
            return 'stable';
        }

        if ($growthRate > self::GROWING_THRESHOLD) {
            return 'growing';
        }

        if ($growthRate < self::DECLINING_THRESHOLD) {
            return 'declining';
        }

        return 'stable';
    }
}
