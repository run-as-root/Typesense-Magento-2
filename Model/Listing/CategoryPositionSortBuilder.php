<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Listing;

use RunAsRoot\TypeSense\Model\Boost\BoostSortBuilder;
use RunAsRoot\TypeSense\Model\Indexer\Product\CategoryPositionResolver;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;

/**
 * sort_by for a category listing in Magento's "position" order: the category's position field, then the
 * category boosting rules like ElasticSuite's score after its positions.
 */
class CategoryPositionSortBuilder
{
    public function __construct(
        private readonly CollectionFieldProvider $fieldProvider,
        private readonly BoostSortBuilder $boostSortBuilder,
    ) {
    }

    /**
     * Null while the collection has no field for the category (collection built before the upgrade, category
     * without indexed products): Typesense rejects a sort on an unknown field, failing the whole listing.
     */
    public function build(int $categoryId, int $storeId, string $collection): ?string
    {
        $field = CategoryPositionResolver::fieldName($categoryId);
        if (!$this->fieldProvider->hasField($collection, $field)) {
            return null;
        }

        $sortBy = $field . ':asc';
        $evalClause = $this->boostSortBuilder->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_CATEGORY, $storeId);

        return $evalClause === null ? $sortBy : $sortBy . ',' . $evalClause . ':desc';
    }
}
