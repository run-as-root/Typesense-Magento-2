<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class FrequentlyBoughtTogetherTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'frequently_bought_together';
    }

    public function getDescription(): string
    {
        return 'Find products that are frequently purchased together in the same order. Optionally filter by a specific product SKU to see what other products are bought with it.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_sku' => [
                    'type' => 'string',
                    'description' => 'Optional. Filter results to show pairs involving this specific product SKU.',
                ],
                'min_occurrences' => [
                    'type' => 'integer',
                    'description' => 'Minimum number of times the pair must appear together (default: 2).',
                    'default' => 2,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of pairs to return (default: 10).',
                    'default' => 10,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): string
    {
        $productSku = isset($arguments['product_sku']) ? trim((string) $arguments['product_sku']) : null;
        $minOccurrences = max(1, (int) ($arguments['min_occurrences'] ?? 2));
        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 10)));

        try {
            $pairs = $this->fetchPairs($productSku, $minOccurrences, $limit);

            return json_encode([
                'product_sku_filter' => $productSku,
                'min_occurrences' => $minOccurrences,
                'pairs' => $pairs,
                'count' => count($pairs),
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPairs(?string $productSku, int $minOccurrences, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $orderItemTable = $this->resource->getTableName('sales_order_item');

        $skuFilter = '';
        $params = [$minOccurrences, $limit];

        if ($productSku !== null && $productSku !== '') {
            $skuFilter = 'AND a.sku = ?';
            $params = [$productSku, $minOccurrences, $limit];
        }

        $sql = "SELECT
                    a.name as product_a_name,
                    a.sku as sku_a,
                    b.name as product_b_name,
                    b.sku as sku_b,
                    COUNT(*) as times_bought_together
                FROM {$orderItemTable} a
                JOIN {$orderItemTable} b
                    ON a.order_id = b.order_id
                    AND a.item_id < b.item_id
                WHERE a.parent_item_id IS NULL
                    AND b.parent_item_id IS NULL
                    {$skuFilter}
                GROUP BY a.sku, b.sku, a.name, b.name
                HAVING times_bought_together >= ?
                ORDER BY times_bought_together DESC
                LIMIT ?";

        return $connection->fetchAll($sql, $params);
    }
}
