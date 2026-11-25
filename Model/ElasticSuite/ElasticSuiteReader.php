<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Reads ElasticSuite's tables directly, so the migration runs without ElasticSuite's code installed.
 */
class ElasticSuiteReader
{
    public const string THESAURUS = 'smile_elasticsuite_thesaurus';
    public const string THESAURUS_STORE = 'smile_elasticsuite_thesaurus_store';
    public const string THESAURUS_EXPANDED_TERMS = 'smile_elasticsuite_thesaurus_expanded_terms';
    public const string THESAURUS_REFERENCE_TERMS = 'smile_elasticsuite_thesaurus_reference_terms';
    public const string OPTIMIZER = 'smile_elasticsuite_optimizer';
    public const string OPTIMIZER_SEARCH_CONTAINER = 'smile_elasticsuite_optimizer_search_container';
    public const string OPTIMIZER_LIMITATION = 'smile_elasticsuite_optimizer_limitation';
    public const string CATEGORY_PRODUCT_POSITION = 'smile_virtualcategory_catalog_category_product_position';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
    ) {
    }

    public function hasTables(string ...$tables): bool
    {
        $connection = $this->connection();

        foreach ($tables as $table) {
            if (!$connection->isTableExists($this->resource->getTableName($table))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{thesaurus_id: int, name: string, type: string, is_active: bool}>
     */
    public function getThesauri(): array
    {
        $select = $this->connection()->select()
            ->from($this->resource->getTableName(self::THESAURUS), ['thesaurus_id', 'name', 'type', 'is_active'])
            ->order('thesaurus_id');
        $rows = $this->connection()->fetchAll($select);

        return array_map(static fn (array $row): array => [
            'thesaurus_id' => (int) $row['thesaurus_id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'is_active' => (bool) $row['is_active'],
        ], $rows);
    }

    /**
     * @return array<int, list<int>> store ids by thesaurus id
     */
    public function getThesaurusStoreIds(): array
    {
        $select = $this->connection()->select()
            ->from($this->resource->getTableName(self::THESAURUS_STORE), ['thesaurus_id', 'store_id'])
            ->order(['thesaurus_id', 'store_id']);
        $rows = $this->connection()->fetchAll($select);

        $storeIds = [];
        foreach ($rows as $row) {
            $storeIds[(int) $row['thesaurus_id']][] = (int) $row['store_id'];
        }

        return $storeIds;
    }

    /**
     * @return list<array{thesaurus_id: int, term_id: int, term: string}>
     */
    public function getExpandedTerms(): array
    {
        return $this->getTerms(self::THESAURUS_EXPANDED_TERMS);
    }

    /**
     * @return list<array{thesaurus_id: int, term_id: int, term: string}> empty without the table (synonyms only)
     */
    public function getReferenceTerms(): array
    {
        return $this->hasTables(self::THESAURUS_REFERENCE_TERMS) ? $this->getTerms(self::THESAURUS_REFERENCE_TERMS) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOptimizers(): array
    {
        $select = $this->connection()->select()
            ->from($this->resource->getTableName(self::OPTIMIZER))
            ->order('optimizer_id');

        return array_values($this->connection()->fetchAll($select));
    }

    /**
     * @return array<int, array<string, int>> apply_to by search container, by optimizer id
     */
    public function getOptimizerContainers(): array
    {
        $table = $this->resource->getTableName(self::OPTIMIZER_SEARCH_CONTAINER);
        $columns = ['optimizer_id', 'search_container'];
        // Older ElasticSuite versions had no limitations, so no apply_to either.
        if ($this->connection()->tableColumnExists($table, 'apply_to')) {
            $columns[] = 'apply_to';
        }
        $rows = $this->connection()->fetchAll($this->connection()->select()->from($table, $columns));

        $containers = [];
        foreach ($rows as $row) {
            $containers[(int) $row['optimizer_id']][(string) $row['search_container']] = (int) ($row['apply_to'] ?? 0);
        }

        return $containers;
    }

    /**
     * @return array<int, array{query_count: int, queries: list<string>, category_count: int}> by optimizer id
     */
    public function getOptimizerLimitations(): array
    {
        if (!$this->hasTables(self::OPTIMIZER_LIMITATION)) {
            return [];
        }

        $select = $this->connection()->select()
            ->from(
                ['l' => $this->resource->getTableName(self::OPTIMIZER_LIMITATION)],
                ['optimizer_id', 'category_id', 'query_id'],
            )
            ->joinLeft(
                ['q' => $this->resource->getTableName('search_query')],
                'q.query_id = l.query_id',
                ['query_text'],
            );
        $rows = $this->connection()->fetchAll($select);

        $limitations = [];
        foreach ($rows as $row) {
            $id = (int) $row['optimizer_id'];
            $limitations[$id] ??= ['query_count' => 0, 'queries' => [], 'category_count' => 0];
            if ($row['category_id'] !== null) {
                $limitations[$id]['category_count']++;
            }
            if ($row['query_id'] === null) {
                continue;
            }
            $limitations[$id]['query_count']++;
            if ($row['query_text'] !== null && !in_array($row['query_text'], $limitations[$id]['queries'], true)) {
                $limitations[$id]['queries'][] = (string) $row['query_text'];
            }
        }

        return $limitations;
    }

    /**
     * @return list<array{category_id: int, product_id: int, store_id: int, position: int|null, is_blacklisted: bool}>
     */
    public function getCategoryProductPositions(): array
    {
        $select = $this->connection()->select()
            ->from(
                $this->resource->getTableName(self::CATEGORY_PRODUCT_POSITION),
                ['category_id', 'product_id', 'store_id', 'position', 'is_blacklisted'],
            )
            ->order(['category_id', 'store_id', 'product_id']);
        $rows = $this->connection()->fetchAll($select);

        return array_map(static fn (array $row): array => [
            'category_id' => (int) $row['category_id'],
            'product_id' => (int) $row['product_id'],
            'store_id' => (int) $row['store_id'],
            'position' => $row['position'] === null ? null : (int) $row['position'],
            'is_blacklisted' => (bool) $row['is_blacklisted'],
        ], $rows);
    }

    /**
     * ElasticSuite reads a store view's own positions only for categories whose "use_store_positions" is set on
     * that very store view (no fallback to the default value).
     *
     * @return array<int, list<int>> category ids by store id
     */
    public function getStorePositionCategoryIds(): array
    {
        $connection = $this->connection();
        $attributeId = $connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['t' => $this->resource->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    [],
                )
                ->where('t.entity_type_code = ?', Category::ENTITY)
                ->where('a.attribute_code = ?', 'use_store_positions'),
        );
        if (!$attributeId) {
            return [];
        }

        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName('catalog_category_entity_int')], ['store_id'])
            ->join(
                ['c' => $this->resource->getTableName('catalog_category_entity')],
                sprintf('c.%1$s = v.%1$s', $linkField),
                ['entity_id'],
            )
            ->where('v.attribute_id = ?', (int) $attributeId)
            ->where('v.store_id > 0')
            ->where('v.value = 1');
        $rows = $connection->fetchAll($select);

        $categoryIds = [];
        foreach ($rows as $row) {
            $categoryIds[(int) $row['store_id']][] = (int) $row['entity_id'];
        }

        return $categoryIds;
    }

    /**
     * @return list<array{thesaurus_id: int, term_id: int, term: string}>
     */
    private function getTerms(string $table): array
    {
        $select = $this->connection()->select()
            ->from($this->resource->getTableName($table), ['thesaurus_id', 'term_id', 'term'])
            ->order(['thesaurus_id', 'term_id', 'term']);
        $rows = $this->connection()->fetchAll($select);

        return array_map(static fn (array $row): array => [
            'thesaurus_id' => (int) $row['thesaurus_id'],
            'term_id' => (int) $row['term_id'],
            'term' => (string) $row['term'],
        ], $rows);
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
