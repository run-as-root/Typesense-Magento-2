<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Suggestion;

use Magento\Framework\App\ResourceConnection;

class SearchQueryReader
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * The store's most popular search terms that found products, in a single limited query: shops collect
     * tens of thousands of one-off terms, so the table is never loaded as a whole.
     *
     * @return list<array{query: string, popularity: int}>
     */
    public function getTopQueries(int $storeId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('search_query');
        $select = $connection->select()
            ->from($table, ['query_text', 'popularity'])
            ->where('store_id = ?', $storeId)
            ->where('num_results > 0');
        if ($connection->tableColumnExists($table, 'is_active')) {
            $select->where('is_active = ?', 1);
        }
        $select->order(['popularity DESC', 'query_id ASC'])->limit($limit);

        $rows = $connection->fetchAll($select);

        return array_map(
            static fn (array $row): array => [
                'query' => (string) $row['query_text'],
                'popularity' => (int) $row['popularity'],
            ],
            $rows,
        );
    }
}
