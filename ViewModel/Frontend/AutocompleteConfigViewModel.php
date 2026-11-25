<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProviderInterface;
use RunAsRoot\TypeSense\Model\Search\SearchParametersProvider;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;

/**
 * Configuration of the header search (`search/form-mini.phtml`). It holds no connection data: requests go through
 * the shared client of `js/client-loader.phtml`, which only ever gets the search-only key.
 */
class AutocompleteConfigViewModel implements ArgumentInterface
{
    private const string FIELD_NAME = '/^[A-Za-z0-9_][A-Za-z0-9_.]*$/';
    private const string CROSS_SELL_FIELD = 'crosssell_ids';

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly SearchRequestParamsBuilder $requestParamsBuilder,
        private readonly SearchParametersProvider $searchParametersProvider,
        private readonly SearchHelper $searchHelper,
        private readonly ProductSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isAutocompleteEnabled();
    }

    /**
     * Product prices in the dropdown and in GA4 items. Shops that hide prices (e.g. for guests) change this with
     * an after plugin, as on ListingConfigViewModel::canShowPrices().
     */
    public function canShowPrices(): bool
    {
        return true;
    }

    /**
     * Document fields the product searches return (`include_fields`); the group-by field is added in the browser.
     * A theme whose dropdown shows more fields adds them with an after plugin.
     *
     * @return string[]
     */
    public function getProductFields(): array
    {
        return ['id', 'name', 'sku', 'url', 'image_url', 'final_price', 'price'];
    }

    public function getMinQueryLength(): int
    {
        return max(1, (int) $this->searchHelper->getMinQueryLength());
    }

    public function getMaxQueryLength(): int
    {
        return (int) $this->searchHelper->getMaxQueryLength();
    }

    public function getResultUrl(): string
    {
        return (string) $this->searchHelper->getResultUrl();
    }

    public function getQueryParamName(): string
    {
        return (string) $this->searchHelper->getQueryParamName();
    }

    public function getEscapedQueryText(): string
    {
        return (string) $this->searchHelper->getEscapedQueryText();
    }

    /**
     * A section collection is null when its entity is not indexed or its count is 0, so no search is sent for it.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $storeCode = $store->getCode();
        $storeId = (int) $store->getId();
        $categoryCount = $this->config->getAutocompleteCategoryCount($storeId);
        $pageCount = $this->config->getAutocompleteCmsPageCount($storeId);
        $suggestionCount = $this->config->getAutocompleteSuggestionCount($storeId);
        $suggestionsIndexed = $this->config->isSuggestionIndexingEnabled($storeId);
        $productMetaFields = $this->getFieldNames($this->config->getAutocompleteProductMetaFields($storeId));
        $crossSell = $this->getCrossSellConfig($storeId);
        $crossSellFields = $crossSell['crossSellCount'] > 0
            ? [self::CROSS_SELL_FIELD, $crossSell['crossSellFallbackField'], ...$crossSell['crossSellMetaFields']]
            : [];

        return [
            'minQueryLength'       => $this->getMinQueryLength(),
            'searchUrl'            => $this->getResultUrl(),
            'baseUrl'              => (string) $store->getBaseUrl(),
            'productCollection'    => $this->collectionNameResolver->resolve('product', $storeCode, $storeId),
            'categoryCollection'   => $this->config->isCategoryIndexingEnabled($storeId) && $categoryCount > 0
                ? $this->collectionNameResolver->resolve('category', $storeCode, $storeId)
                : null,
            'pageCollection'       => $this->config->isCmsPageIndexingEnabled($storeId) && $pageCount > 0
                ? $this->collectionNameResolver->resolve('cms_page', $storeCode, $storeId)
                : null,
            'suggestionCollection' => $suggestionsIndexed && $suggestionCount > 0
                ? $this->collectionNameResolver->resolve('suggestion', $storeCode, $storeId)
                : null,
            'productCount'         => $this->config->getAutocompleteProductCount($storeId),
            'categoryCount'        => $categoryCount,
            'pageCount'            => $pageCount,
            'suggestionCount'      => $suggestionCount,
            'groupBy'              => $this->getGroupByField($storeId),
            'highlight'            => $this->config->isHighlightEnabled($storeId),
            'showPrices'           => $this->canShowPrices(),
            'productFields'        => array_values(array_unique(array_filter([
                ...$this->getProductFields(),
                ...$productMetaFields,
                ...$crossSellFields,
            ]))),
            'productMetaFields'    => $productMetaFields,
            ...$crossSell,
            // Popular searches are read from the suggestion collection, which may be off for the store.
            'popularSearchCount'   => $suggestionsIndexed
                ? $this->config->getAutocompletePopularSearchCount($storeId)
                : 0,
            'requestParams'        => $this->requestParamsBuilder->build(SearchRequestParamsBuilder::CONTEXT_AUTOCOMPLETE, $storeId),
            'directMatchesFirst'   => $this->config->isDirectMatchesFirstEnabled($storeId),
            'cacheParams'          => $this->searchParametersProvider->getCacheParameters($storeId),
            'relevanceSortBy'      => $this->requestParamsBuilder->getRelevanceSortBy(SearchRequestParamsBuilder::CONTEXT_AUTOCOMPLETE, $storeId),
        ];
    }

    public function getJsonConfig(): string
    {
        return (string) json_encode(
            $this->getConfig(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }

    /**
     * @param string[] $fields
     * @return list<string>
     */
    private function getFieldNames(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            static fn (string $field): bool => preg_match(self::FIELD_NAME, $field) === 1,
        ));
    }

    /**
     * @return array{
     *     crossSellCount: int,
     *     crossSellFallbackField: string|null,
     *     crossSellFallbackSortBy: string|null,
     *     crossSellMetaFields: list<string>,
     * }
     */
    private function getCrossSellConfig(int $storeId): array
    {
        $count = $this->config->isAutocompleteCrossSellEnabled($storeId)
            ? $this->config->getAutocompleteCrossSellCount($storeId)
            : 0;
        if ($count === 0) {
            return [
                'crossSellCount' => 0,
                'crossSellFallbackField' => null,
                'crossSellFallbackSortBy' => null,
                'crossSellMetaFields' => [],
            ];
        }

        $fallbackField = $this->getCrossSellFallbackField($storeId);
        $fallbackSortBy = $this->config->getAutocompleteCrossSellFallbackSort($storeId);

        return [
            'crossSellCount' => $count,
            'crossSellFallbackField' => $fallbackField,
            'crossSellFallbackSortBy' => $fallbackField !== null && $fallbackSortBy !== '' ? $fallbackSortBy : null,
            'crossSellMetaFields' => $this->getFieldNames($this->config->getAutocompleteCrossSellMetaFields($storeId)),
        ];
    }

    // Typesense rejects a filter on a field that is not in the schema, which would fail the fallback search.
    private function getCrossSellFallbackField(int $storeId): ?string
    {
        $field = $this->config->getAutocompleteCrossSellFallbackAttribute($storeId);
        if ($field === '') {
            return null;
        }

        $schemaFields = array_column($this->schemaProvider->getFields(), 'name');

        return in_array($field, $schemaFields, true) ? $field : null;
    }

    // Typesense rejects group_by on a field that is missing or not faceted, which would fail the whole dropdown.
    private function getGroupByField(int $storeId): ?string
    {
        $field = $this->config->getAutocompleteGroupBy($storeId);
        if ($field === '') {
            return null;
        }

        $fields = $this->schemaProvider->getFields();
        foreach ($fields as $schemaField) {
            if (($schemaField['name'] ?? null) === $field && ($schemaField['facet'] ?? false) === true) {
                return $field;
            }
        }

        return null;
    }
}
