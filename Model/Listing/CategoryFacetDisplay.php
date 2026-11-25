<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Listing;

use Magento\Framework\App\ResourceConnection;

/**
 * Per category, which listing filters are always hidden or always shown. The modes match ElasticSuite's
 * category filter display modes, so its settings import unchanged. Filters without a row (or "auto") show
 * whenever they have values.
 */
class CategoryFacetDisplay
{
    public const int AUTO = 0;
    public const int HIDDEN = 1;
    public const int ALWAYS = 2;
    public const array MODES = [self::AUTO, self::HIDDEN, self::ALWAYS];

    private const string TABLE = 'run_as_root_typesense_category_facet';

    /** @var array<int, array<string, int>> */
    private array $modes = [];

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * @return array<string, int> facet field => display mode, ordered by field
     */
    public function getModes(int $categoryId): array
    {
        if (isset($this->modes[$categoryId])) {
            return $this->modes[$categoryId];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['attribute_code', 'display_mode'])
            ->where('category_id = ?', $categoryId)
            ->order('attribute_code');
        $pairs = $connection->fetchPairs($select);

        return $this->modes[$categoryId] = array_map('intval', $pairs);
    }

    /**
     * @return list<string>
     */
    public function getHiddenFields(int $categoryId): array
    {
        $modes = $this->getModes($categoryId);

        return array_keys(array_filter($modes, static fn (int $mode): bool => $mode === self::HIDDEN));
    }

    /**
     * Replaces the category's settings; "auto" rows are not stored, as they equal having none.
     *
     * @param array<string, int> $modes facet field => display mode
     */
    public function replace(int $categoryId, array $modes): void
    {
        $rows = [];
        foreach ($modes as $field => $mode) {
            if ($field !== '' && $mode !== self::AUTO && in_array($mode, self::MODES, true)) {
                $rows[] = ['category_id' => $categoryId, 'attribute_code' => $field, 'display_mode' => $mode];
            }
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->beginTransaction();
        try {
            $connection->delete($table, ['category_id = ?' => $categoryId]);
            if ($rows !== []) {
                $connection->insertMultiple($table, $rows);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        unset($this->modes[$categoryId]);
    }
}
