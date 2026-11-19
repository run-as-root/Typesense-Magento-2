<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Search;

class SearchRequestParamsBuilder
{
    public const string CONTEXT_SEARCH = 'search';
    public const string CONTEXT_AUTOCOMPLETE = 'autocomplete';
    public const string CONTEXT_CATEGORY = 'category';

    public function __construct(
        private readonly SearchParametersProvider $searchParametersProvider,
        private readonly SearchableAttributesProvider $searchableAttributesProvider,
    ) {
    }

    /**
     * Typesense parameters for a product search in the given context; templates merge their
     * per-request q/page/filter_by/facet_by on top of this.
     *
     * @return array<string, mixed>
     */
    public function build(string $context, ?int $storeId = null): array
    {
        $fields = $this->searchableAttributesProvider->getWeightedFields($storeId);

        return array_merge(
            $this->searchParametersProvider->getParameters($storeId),
            [
                'query_by' => implode(',', array_keys($fields)),
                'query_by_weights' => implode(',', $fields),
            ],
        );
    }

    /**
     * sort_by for "relevance" ordering; null lets Typesense use its default text-match sort.
     */
    public function getRelevanceSortBy(string $context, ?int $storeId = null): ?string
    {
        return null;
    }
}
