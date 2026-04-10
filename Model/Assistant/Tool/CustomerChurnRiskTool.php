<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class CustomerChurnRiskTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'customer_churn_risk';
    }

    public function getDescription(): string
    {
        return 'Identify customers at risk of churning based on their purchase interval patterns. Compares days since last order to their average order interval to classify risk as high, medium, or low.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'risk_level' => [
                    'type' => 'string',
                    'enum' => ['high', 'medium', 'low'],
                    'description' => 'Optional filter to return only customers at a specific risk level.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 20).',
                    'default' => 20,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): string
    {
        $riskLevel = $arguments['risk_level'] ?? null;
        $limit = max(1, (int) ($arguments['limit'] ?? 20));

        $validRiskLevels = ['high', 'medium', 'low'];
        if ($riskLevel !== null && !in_array($riskLevel, $validRiskLevels, true)) {
            return json_encode(['error' => 'Invalid risk_level: ' . $riskLevel]);
        }

        try {
            return $this->churnRisk($riskLevel, $limit);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function churnRisk(?string $riskLevel, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');

        $riskFilter = '';
        if ($riskLevel === 'high') {
            $riskFilter = "HAVING risk_level = 'high'";
        } elseif ($riskLevel === 'medium') {
            $riskFilter = "HAVING risk_level = 'medium'";
        } elseif ($riskLevel === 'low') {
            $riskFilter = "HAVING risk_level = 'low'";
        }

        $sql = "SELECT
                    customer_email,
                    customer_firstname,
                    customer_lastname,
                    order_count,
                    ROUND(avg_interval_days, 1) as avg_interval_days,
                    ROUND(days_since_last_order, 1) as days_since_last_order,
                    CASE
                        WHEN days_since_last_order > avg_interval_days * 2 THEN 'high'
                        WHEN days_since_last_order > avg_interval_days * 1.5 THEN 'medium'
                        ELSE 'low'
                    END as risk_level
                FROM (
                    SELECT
                        customer_email,
                        customer_firstname,
                        customer_lastname,
                        COUNT(*) as order_count,
                        DATEDIFF(MAX(created_at), MIN(created_at)) / NULLIF(COUNT(*) - 1, 0) as avg_interval_days,
                        DATEDIFF(NOW(), MAX(created_at)) as days_since_last_order
                    FROM {$so}
                    WHERE customer_email IS NOT NULL
                        AND status != 'canceled'
                    GROUP BY customer_email, customer_firstname, customer_lastname
                    HAVING order_count >= 2
                ) stats
                {$riskFilter}
                ORDER BY days_since_last_order DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, ['limit' => $limit]);

        return json_encode([
            'risk_level_filter' => $riskLevel,
            'rows' => array_map(fn(array $r) => [
                'customer_email' => $r['customer_email'],
                'name' => trim(($r['customer_firstname'] ?? '') . ' ' . ($r['customer_lastname'] ?? '')),
                'order_count' => (int) $r['order_count'],
                'avg_interval_days' => round((float) $r['avg_interval_days'], 1),
                'days_since_last_order' => round((float) $r['days_since_last_order'], 1),
                'risk_level' => $r['risk_level'],
            ], $rows),
        ]);
    }
}
