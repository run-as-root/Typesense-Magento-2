<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;

class ReviewResolver implements ReviewResolverInterface
{
    /** @var array<int, array<int, array{rating_summary: int, reviews_count: int}>> */
    private ?array $cache = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function getRatingSummary(int $productId, int $storeId): int
    {
        $this->ensureLoaded($storeId);

        return $this->cache[$storeId][$productId]['rating_summary'] ?? 0;
    }

    public function getReviewCount(int $productId, int $storeId): int
    {
        $this->ensureLoaded($storeId);

        return $this->cache[$storeId][$productId]['reviews_count'] ?? 0;
    }

    private function ensureLoaded(int $storeId): void
    {
        if (isset($this->cache[$storeId])) {
            return;
        }

        $this->cache[$storeId] = [];

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('review_entity_summary');

        if (!$connection->isTableExists($tableName)) {
            return;
        }

        $select = $connection->select()
            ->from($tableName, ['entity_pk_value', 'rating_summary', 'reviews_count'])
            ->where('store_id = ?', $storeId)
            ->where('entity_type = ?', 1);

        $rows = $connection->fetchAll($select);

        foreach ($rows as $row) {
            $this->cache[$storeId][(int) $row['entity_pk_value']] = [
                'rating_summary' => (int) $row['rating_summary'],
                'reviews_count' => (int) $row['reviews_count'],
            ];
        }
    }
}
