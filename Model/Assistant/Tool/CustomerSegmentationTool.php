<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class CustomerSegmentationTool implements ToolInterface
{
    // Segment name constants
    private const SEGMENT_CHAMPIONS = 'Champions';
    private const SEGMENT_LOYAL = 'Loyal';
    private const SEGMENT_POTENTIAL = 'Potential Loyalists';
    private const SEGMENT_AT_RISK = 'At Risk';
    private const SEGMENT_LOST = 'Lost';

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'customer_segmentation';
    }

    public function getDescription(): string
    {
        return 'Segment all customers using RFM analysis (Recency, Frequency, Monetary). Returns segment summary with Champions, Loyal, Potential Loyalists, At Risk, and Lost customers. No parameters needed.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $rows = $this->fetchCustomerRfm();

            if (empty($rows)) {
                return json_encode([
                    'segments' => [],
                    'total_customers' => 0,
                    'message' => 'No customer data found.',
                ]);
            }

            $rows = $this->scoreRfm($rows);
            $segmented = $this->assignSegments($rows);
            $summary = $this->buildSummary($segmented);

            return json_encode([
                'total_customers' => count($rows),
                'segments' => $summary,
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCustomerRfm(): array
    {
        $connection = $this->resource->getConnection();
        $customerTable = $this->resource->getTableName('customer_entity');
        $orderTable = $this->resource->getTableName('sales_order');

        $sql = "SELECT
                    ce.entity_id,
                    COALESCE(DATEDIFF(NOW(), MAX(so.created_at)), 9999) as recency_days,
                    COUNT(so.entity_id) as frequency,
                    COALESCE(SUM(so.grand_total), 0) as monetary
                FROM {$customerTable} ce
                LEFT JOIN {$orderTable} so ON so.customer_id = ce.entity_id
                GROUP BY ce.entity_id";

        return $connection->fetchAll($sql);
    }

    /**
     * Calculate RFM quintile scores (1-5) for all customers.
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function scoreRfm(array $rows): array
    {
        $recencyValues = array_column($rows, 'recency_days');
        $frequencyValues = array_column($rows, 'frequency');
        $monetaryValues = array_column($rows, 'monetary');

        // Recency: lower is better (recent = high score)
        $rScores = $this->calculateQuintiles($recencyValues, true);
        // Frequency: higher is better
        $fScores = $this->calculateQuintiles($frequencyValues, false);
        // Monetary: higher is better
        $mScores = $this->calculateQuintiles($monetaryValues, false);

        foreach ($rows as $i => &$row) {
            $row['r_score'] = $rScores[$i];
            $row['f_score'] = $fScores[$i];
            $row['m_score'] = $mScores[$i];
        }
        unset($row);

        return $rows;
    }

    /**
     * Calculate quintile scores (1-5) for an array of values.
     * @param float[] $values
     * @return int[]
     */
    private function calculateQuintiles(array $values, bool $invertOrder): array
    {
        $count = count($values);
        if ($count === 0) {
            return [];
        }

        // Create indexed pairs and sort
        $indexed = array_map(null, range(0, $count - 1), $values);
        usort($indexed, fn ($a, $b) => $a[1] <=> $b[1]);

        $scores = [];
        foreach ($indexed as $rank => $pair) {
            [$originalIndex] = $pair;
            $quintile = (int) floor($rank / $count * 5) + 1;
            $quintile = min($quintile, 5);

            // For recency (lower = better), invert so recent customers get score 5
            $scores[$originalIndex] = $invertOrder ? (6 - $quintile) : $quintile;
        }

        return $scores;
    }

    /**
     * Assign segment names based on RFM scores.
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function assignSegments(array $rows): array
    {
        foreach ($rows as &$row) {
            $r = (int) $row['r_score'];
            $f = (int) $row['f_score'];
            $m = (int) $row['m_score'];

            $row['segment'] = $this->classifySegment($r, $f, $m);
        }
        unset($row);

        return $rows;
    }

    private function classifySegment(int $r, int $f, int $m): string
    {
        // Champions: bought recently, buy often, spend the most
        if ($r >= 4 && $f >= 4 && $m >= 4) {
            return self::SEGMENT_CHAMPIONS;
        }

        // Loyal: buy often
        if ($f >= 4) {
            return self::SEGMENT_LOYAL;
        }

        // Potential Loyalists: recent customers with average frequency
        if ($r >= 3 && $f >= 2) {
            return self::SEGMENT_POTENTIAL;
        }

        // At Risk: previously high value but haven't purchased recently
        if ($r <= 2 && ($f >= 3 || $m >= 3)) {
            return self::SEGMENT_AT_RISK;
        }

        // Lost: low recency, low frequency, low monetary
        if ($r <= 2 && $f <= 2) {
            return self::SEGMENT_LOST;
        }

        return self::SEGMENT_POTENTIAL;
    }

    /**
     * Build aggregate summary per segment.
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function buildSummary(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $segment = $row['segment'];
            if (!isset($grouped[$segment])) {
                $grouped[$segment] = ['count' => 0, 'total_monetary' => 0.0, 'total_frequency' => 0];
            }
            $grouped[$segment]['count']++;
            $grouped[$segment]['total_monetary'] += (float) $row['monetary'];
            $grouped[$segment]['total_frequency'] += (int) $row['frequency'];
        }

        $summary = [];
        foreach ($grouped as $segment => $data) {
            $count = $data['count'];
            $summary[$segment] = [
                'segment' => $segment,
                'customer_count' => $count,
                'avg_lifetime_value' => round($data['total_monetary'] / $count, 2),
                'avg_order_count' => round($data['total_frequency'] / $count, 2),
            ];
        }

        return $summary;
    }
}
