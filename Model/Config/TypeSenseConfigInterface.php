<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config;

interface TypeSenseConfigInterface
{
    public function isEnabled(?int $storeId = null): bool;

    public function getProtocol(?int $storeId = null): string;

    public function getHost(?int $storeId = null): string;

    public function getPort(?int $storeId = null): int;

    public function getApiKey(?int $storeId = null): string;

    public function getSearchOnlyApiKey(?int $storeId = null): string;

    public function getIndexPrefix(?int $storeId = null): string;

    public function getSearchProtocol(?int $storeId = null): string;

    public function getSearchHost(?int $storeId = null): string;

    public function getSearchPort(?int $storeId = null): int;

    public function isLogEnabled(?int $storeId = null): bool;

    public function getCollectionName(string $entityType, string $storeCode, ?int $storeId = null): string;

    public function getBatchSize(?int $storeId = null): int;

    public function isProductIndexingEnabled(?int $storeId = null): bool;

    public function isCategoryIndexingEnabled(?int $storeId = null): bool;

    public function isCmsPageIndexingEnabled(?int $storeId = null): bool;

    public function isSuggestionIndexingEnabled(?int $storeId = null): bool;

    /** Reads `indexing/<entity type>_enabled`; an entity type without such a flag is always indexed. */
    public function isEntityIndexingEnabled(string $entityType, ?int $storeId = null): bool;

    /** How many popular queries the store's suggestion collection keeps (Typesense analytics rule `limit`). */
    public function getSuggestionLimit(?int $storeId = null): int;

    /** How often a query must have been counted before it is suggested (at least 1). */
    public function getSuggestionMinCount(?int $storeId = null): int;

    /** Longer suggestion terms are neither suggested nor kept. */
    public function getSuggestionMaxLength(?int $storeId = null): int;

    /**
     * Blocked words or word parts, lowercased; a suggestion containing one is neither suggested nor kept.
     *
     * @return list<string>
     */
    public function getSuggestionDenylist(?int $storeId = null): array;

    public function isZeroDowntimeEnabled(?int $storeId = null): bool;

    public function isCronEnabled(?int $storeId = null): bool;

    public function getCronSchedule(?int $storeId = null): string;

    public function isQueueEnabled(?int $storeId = null): bool;

    public function isTypoToleranceEnabled(?int $storeId = null): bool;

    public function isHighlightEnabled(?int $storeId = null): bool;

    public function getTextMatchType(?int $storeId = null): string;

    public function isInfixEnabled(?int $storeId = null): bool;

    /** @return string[] */
    public function getInfixFields(?int $storeId = null): array;

    public function getInfixMode(?int $storeId = null): string;

    public function isStemEnabled(?int $storeId = null): bool;

    public function isDirectMatchesFirstEnabled(?int $storeId = null): bool;

    public function isGermanDecompoundingEnabled(?int $storeId = null): bool;

    public function getDecompoundSkipAttribute(?int $storeId = null): string;

    public function getDropTokensThreshold(?int $storeId = null): int;

    public function getTypoTokensThreshold(?int $storeId = null): int;

    public function getMinLen1Typo(?int $storeId = null): int;

    public function getMinLen2Typo(?int $storeId = null): int;

    public function isPrioritizeExactMatch(?int $storeId = null): bool;

    public function getSplitJoinTokens(?int $storeId = null): string;

    public function isPrefixSearchEnabled(?int $storeId = null): bool;

    /** Whether Typesense considers every candidate instead of stopping once it found enough good matches. */
    public function isExhaustiveSearchEnabled(?int $storeId = null): bool;

    public function isSearchCacheEnabled(?int $storeId = null): bool;

    public function getSearchCacheTtl(?int $storeId = null): int;

    /**
     * Number of text-match buckets boosting rules reorder within; always positive.
     */
    public function getBoostTextMatchBuckets(?int $storeId = null): int;

    public function isInstantSearchEnabled(?int $storeId = null): bool;

    public function getProductsPerPage(?int $storeId = null): int;

    public function isReplaceCategoryPage(?int $storeId = null): bool;

    public function isCacheableSearchPageEnabled(?int $storeId = null): bool;

    public function getListingImageId(?int $storeId = null): string;

    public function getListingImageWidth(?int $storeId = null): int;

    public function getListingImageHeight(?int $storeId = null): int;

    public function isInStockFirst(?int $storeId = null): bool;

    /** Category whose products are suggested when a listing has no results; null disables the suggestions. */
    public function getNoResultsCategoryId(?int $storeId = null): ?int;

    /** CSS selector of the SEO text hidden on listing pages after the first; empty disables it. */
    public function getSeoTextSelector(?int $storeId = null): string;

    /** @return array<string, array{type: 'dropdown'|'checkbox'|'slider', searchable: bool}> */
    public function getFacetTypes(?int $storeId = null): array;

    public function isAutocompleteEnabled(?int $storeId = null): bool;

    public function getAutocompleteProductCount(?int $storeId = null): int;

    public function getAutocompleteCategoryCount(?int $storeId = null): int;

    public function getAutocompleteCmsPageCount(?int $storeId = null): int;

    public function getAutocompleteSuggestionCount(?int $storeId = null): int;

    /** Product field the header search groups hits by (one hit per value); empty disables grouping. */
    public function getAutocompleteGroupBy(?int $storeId = null): string;

    /**
     * Product document fields shown as the meta line of a header search product row, in order.
     *
     * @return string[]
     */
    public function getAutocompleteProductMetaFields(?int $storeId = null): array;

    public function isAutocompleteCrossSellEnabled(?int $storeId = null): bool;

    public function getAutocompleteCrossSellCount(?int $storeId = null): int;

    /** Product field whose values the cross-sell fallback shares with the top hit; empty disables the fallback. */
    public function getAutocompleteCrossSellFallbackAttribute(?int $storeId = null): string;

    public function getAutocompleteCrossSellFallbackSort(?int $storeId = null): string;

    /** @return string[] */
    public function getAutocompleteCrossSellMetaFields(?int $storeId = null): array;

    public function getAutocompletePopularSearchCount(?int $storeId = null): int;

    public function isCategoryMerchandiserEnabled(?int $storeId = null): bool;

    public function isQueryMerchandiserEnabled(?int $storeId = null): bool;

    /** @return string[] */
    public function getAdditionalAttributes(?int $storeId = null): array;

    /** @return list<array{attribute: string, weight: int}> */
    public function getSearchableAttributes(?int $storeId = null): array;

    /** @return string[] */
    public function getTileAttributes(?int $storeId = null): array;

    /** @return string[] */
    public function getFacetFilters(?int $storeId = null): array;

    /** @return array<int, array{label: string, value: string}> */
    public function getEnabledSortOptions(?int $storeId = null): array;

    public function isConversationalSearchEnabled(?int $storeId = null): bool;

    public function getOpenAiApiKey(?int $storeId = null): string;

    public function getOpenAiModel(?int $storeId = null): string;

    public function getConversationalSystemPrompt(?int $storeId = null): string;

    public function getEmbeddingFields(?int $storeId = null): array;

    public function getConversationTtl(?int $storeId = null): int;

    public function isRecommendationsEnabled(?int $storeId = null): bool;

    public function getRecommendationsLimit(?int $storeId = null): int;

    public function isAdminAssistantEnabled(?int $storeId = null): bool;

    public function getAdminAssistantSystemPrompt(?int $storeId = null): string;

    public function getAdminAssistantOpenAiModel(?int $storeId = null): string;

    public function getAdminAssistantConversationTtl(?int $storeId = null): int;
}
