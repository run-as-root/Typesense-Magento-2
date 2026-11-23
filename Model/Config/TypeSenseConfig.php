<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;
use RunAsRoot\TypeSense\Model\Config\Source\ImageType;

class TypeSenseConfig implements TypeSenseConfigInterface
{
    private const string CONFIG_PREFIX = 'run_as_root_typesense';
    private const string DEFAULT_LISTING_IMAGE_ID = 'category_page_grid';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('general/enabled', $storeId);
    }

    public function getProtocol(?int $storeId = null): string
    {
        return (string) $this->getValue('general/protocol', $storeId);
    }

    public function getHost(?int $storeId = null): string
    {
        return (string) $this->getValue('general/host', $storeId);
    }

    public function getPort(?int $storeId = null): int
    {
        return (int) $this->getValue('general/port', $storeId);
    }

    public function getApiKey(?int $storeId = null): string
    {
        $encrypted = (string) $this->getValue('general/api_key', $storeId);
        return $this->encryptor->decrypt($encrypted);
    }

    public function getSearchOnlyApiKey(?int $storeId = null): string
    {
        return (string) $this->getValue('general/search_only_api_key', $storeId);
    }

    public function getIndexPrefix(?int $storeId = null): string
    {
        return (string) $this->getValue('general/index_prefix', $storeId);
    }

    public function getSearchProtocol(?int $storeId = null): string
    {
        return (string) ($this->getValue('general/search_protocol', $storeId) ?: $this->getProtocol($storeId));
    }

    public function getSearchHost(?int $storeId = null): string
    {
        return (string) ($this->getValue('general/search_host', $storeId) ?: $this->getHost($storeId));
    }

    public function getSearchPort(?int $storeId = null): int
    {
        $port = $this->getValue('general/search_port', $storeId);
        return $port ? (int) $port : $this->getPort($storeId);
    }

    public function isLogEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('general/log_enabled', $storeId);
    }

    public function getCollectionName(string $entityType, string $storeCode, ?int $storeId = null): string
    {
        return sprintf('%s_%s_%s', $this->getIndexPrefix($storeId), $entityType, $storeCode);
    }

    // Indexing
    public function getBatchSize(?int $storeId = null): int
    {
        return (int) $this->getValue('indexing/batch_size', $storeId);
    }

    public function isProductIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/product_enabled', $storeId);
    }

    public function isCategoryIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/category_enabled', $storeId);
    }

    public function isCmsPageIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/cms_page_enabled', $storeId);
    }

    public function isSuggestionIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/suggestion_enabled', $storeId);
    }

    public function isZeroDowntimeEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/zero_downtime_enabled', $storeId);
    }

    public function isCronEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/cron_enabled', $storeId);
    }

    public function getCronSchedule(?int $storeId = null): string
    {
        return (string) $this->getValue('indexing/cron_schedule', $storeId);
    }

    public function isQueueEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/queue_enabled', $storeId);
    }

    // Search
    public function isTypoToleranceEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/typo_tolerance_enabled', $storeId);
    }

    public function isHighlightEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/highlight_enabled', $storeId);
    }

    public function getTextMatchType(?int $storeId = null): string
    {
        return (string) ($this->getValue('search/text_match_type', $storeId) ?: 'max_weight');
    }

    public function isInfixEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/infix_enabled', $storeId);
    }

    public function getInfixFields(?int $storeId = null): array
    {
        $value = (string) $this->getValue('search/infix_fields', $storeId);

        return $value === '' ? [] : explode(',', $value);
    }

    public function getInfixMode(?int $storeId = null): string
    {
        return $this->getValue('search/infix_mode', $storeId) === 'fallback' ? 'fallback' : 'always';
    }

    public function isDirectMatchesFirstEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/direct_matches_first', $storeId);
    }

    public function isStemEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/stem_enabled', $storeId);
    }

    public function isGermanDecompoundingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/german_decompounding', $storeId);
    }

    public function getDecompoundSkipAttribute(?int $storeId = null): string
    {
        return (string) $this->getValue('search/decompound_skip_attribute', $storeId);
    }

    public function getDropTokensThreshold(?int $storeId = null): int
    {
        return $this->getIntOrDefault('search/drop_tokens_threshold', 0, $storeId);
    }

    public function getTypoTokensThreshold(?int $storeId = null): int
    {
        return $this->getIntOrDefault('search/typo_tokens_threshold', 1, $storeId);
    }

    public function getMinLen1Typo(?int $storeId = null): int
    {
        return $this->getIntOrDefault('search/min_len_1typo', 5, $storeId);
    }

    public function getMinLen2Typo(?int $storeId = null): int
    {
        return $this->getIntOrDefault('search/min_len_2typo', 8, $storeId);
    }

    public function isPrioritizeExactMatch(?int $storeId = null): bool
    {
        $value = $this->getValue('search/prioritize_exact_match', $storeId);

        return $value === null || $value === '' || (bool) $value;
    }

    public function getSplitJoinTokens(?int $storeId = null): string
    {
        return (string) ($this->getValue('search/split_join_tokens', $storeId) ?: 'fallback');
    }

    public function isPrefixSearchEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/prefix_search', $storeId);
    }

    public function isSearchCacheEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/use_cache', $storeId);
    }

    public function getSearchCacheTtl(?int $storeId = null): int
    {
        return $this->getIntOrDefault('search/cache_ttl', 300, $storeId);
    }

    public function getBoostTextMatchBuckets(?int $storeId = null): int
    {
        $buckets = $this->getIntOrDefault('search/boost_text_match_buckets', 10, $storeId);

        return $buckets > 0 ? $buckets : 10;
    }

    // Instant Search
    public function isInstantSearchEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('instant_search/enabled', $storeId);
    }

    public function getProductsPerPage(?int $storeId = null): int
    {
        return (int) $this->getValue('instant_search/products_per_page', $storeId);
    }

    public function isReplaceCategoryPage(?int $storeId = null): bool
    {
        return $this->getFlag('instant_search/replace_category_page', $storeId);
    }

    public function isCacheableSearchPageEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('instant_search/cacheable_search_page', $storeId);
    }

    public function getListingImageId(?int $storeId = null): string
    {
        $value = (string) $this->getValue('instant_search/image_type', $storeId);

        // Values saved before image ids were offered (image, small_image, thumbnail) would yield placeholders.
        return isset(ImageType::LABELS[$value]) ? $value : self::DEFAULT_LISTING_IMAGE_ID;
    }

    // Autocomplete
    public function getListingImageWidth(?int $storeId = null): int
    {
        return max(0, (int) $this->getValue('instant_search/image_width', $storeId));
    }

    public function getListingImageHeight(?int $storeId = null): int
    {
        return max(0, (int) $this->getValue('instant_search/image_height', $storeId));
    }

    public function isInStockFirst(?int $storeId = null): bool
    {
        return $this->getFlag('instant_search/in_stock_first', $storeId);
    }

    public function getNoResultsCategoryId(?int $storeId = null): ?int
    {
        $categoryId = (int) trim((string) $this->getValue('instant_search/no_results_category_id', $storeId));

        return $categoryId > 0 ? $categoryId : null;
    }

    public function getSeoTextSelector(?int $storeId = null): string
    {
        return trim((string) $this->getValue('instant_search/seo_text_selector', $storeId));
    }

    public function getFacetTypes(?int $storeId = null): array
    {
        $rows = $this->getSerializedRows('instant_search/facet_types', $storeId);

        if (!is_array($rows)) {
            return [];
        }

        $types = [];
        foreach ($rows as $row) {
            $attribute = is_array($row) ? trim((string) ($row['attribute'] ?? '')) : '';
            if ($attribute === '') {
                continue;
            }

            $types[$attribute] = [
                'type' => match ($row['type'] ?? '') {
                    'checkbox' => 'checkbox',
                    'slider' => 'slider',
                    default => 'dropdown',
                },
                'searchable' => (bool) ($row['searchable'] ?? false),
            ];
        }

        return $types;
    }

    public function isAutocompleteEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('autocomplete/enabled', $storeId);
    }

    public function getAutocompleteProductCount(?int $storeId = null): int
    {
        return (int) $this->getValue('autocomplete/product_count', $storeId);
    }

    public function getAutocompleteCategoryCount(?int $storeId = null): int
    {
        return max(0, (int) $this->getValue('autocomplete/category_count', $storeId));
    }

    public function getAutocompleteCmsPageCount(?int $storeId = null): int
    {
        return max(0, (int) $this->getValue('autocomplete/cms_page_count', $storeId));
    }

    public function getAutocompleteSuggestionCount(?int $storeId = null): int
    {
        return max(0, (int) $this->getValue('autocomplete/suggestion_count', $storeId));
    }

    public function getAutocompleteGroupBy(?int $storeId = null): string
    {
        return trim((string) $this->getValue('autocomplete/group_by', $storeId));
    }

    // Merchandising
    public function isCategoryMerchandiserEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('merchandising/category_merchandiser_enabled', $storeId);
    }

    public function isQueryMerchandiserEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('merchandising/query_merchandiser_enabled', $storeId);
    }

    public function getAdditionalAttributes(?int $storeId = null): array
    {
        $value = (string) $this->getValue('indexing/additional_attributes', $storeId);

        if ($value === '') {
            return [];
        }

        return explode(',', $value);
    }

    public function getSearchableAttributes(?int $storeId = null): array
    {
        $rows = $this->getSerializedRows('search/searchable_attributes', $storeId);

        if (!is_array($rows)) {
            return [];
        }

        $attributes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['attribute'] ?? '') === '') {
                continue;
            }

            $attributes[] = ['attribute' => (string) $row['attribute'], 'weight' => max(1, (int) ($row['weight'] ?? 1))];
        }

        return $attributes;
    }

    public function getTileAttributes(?int $storeId = null): array
    {
        $value = (string) $this->getValue('instant_search/tile_attributes', $storeId);

        if ($value === '') {
            return [];
        }

        return explode(',', $value);
    }

    public function getFacetFilters(?int $storeId = null): array
    {
        $value = (string) $this->getValue('instant_search/facet_filters', $storeId);
        $configured = $value !== '' ? explode(',', $value) : [];

        // Always include categories.lvl0 as the first facet
        $facets = ['categories.lvl0'];
        foreach ($configured as $attr) {
            $attr = trim($attr);
            if ($attr !== '' && $attr !== 'categories.lvl0') {
                $facets[] = $attr;
            }
        }
        return $facets;
    }

    public function getEnabledSortOptions(?int $storeId = null): array
    {
        $value = (string) $this->getValue('instant_search/sort_options', $storeId);

        if ($value === '') {
            return [];
        }

        $sortOptionMap = [
            'relevance'     => ['label' => 'Relevance', 'value' => ''],
            'price_asc'     => ['label' => 'Price: Low to High', 'value' => 'price:asc'],
            'price_desc'    => ['label' => 'Price: High to Low', 'value' => 'price:desc'],
            'newest'        => ['label' => 'Newest', 'value' => 'created_at:desc'],
            'name_asc'      => ['label' => 'Name: A–Z', 'value' => 'name:asc'],
            'name_desc'     => ['label' => 'Name: Z–A', 'value' => 'name:desc'],
            'best_selling'  => ['label' => 'Best Selling', 'value' => 'sales_count:desc'],
            'top_rated'     => ['label' => 'Top Rated', 'value' => 'rating_summary:desc'],
            'most_reviewed' => ['label' => 'Most Reviewed', 'value' => 'review_count:desc'],
        ];

        $options = [];

        foreach (explode(',', $value) as $key) {
            $key = trim($key);

            if (isset($sortOptionMap[$key])) {
                $options[] = $sortOptionMap[$key];
            }
        }

        return $options;
    }

    // Conversational Search
    public function isConversationalSearchEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && (bool) $this->getValue('conversational_search/enabled', $storeId);
    }

    public function getOpenAiApiKey(?int $storeId = null): string
    {
        $encrypted = (string) $this->getValue('conversational_search/openai_api_key', $storeId);
        return $this->encryptor->decrypt($encrypted);
    }

    public function getOpenAiModel(?int $storeId = null): string
    {
        return (string) ($this->getValue('conversational_search/openai_model', $storeId) ?: 'openai/gpt-4o-mini');
    }

    public function getConversationalSystemPrompt(?int $storeId = null): string
    {
        return (string) $this->getValue('conversational_search/system_prompt', $storeId);
    }

    public function getEmbeddingFields(?int $storeId = null): array
    {
        $value = (string) $this->getValue('conversational_search/embedding_fields', $storeId);
        return $value !== '' ? explode(',', $value) : ['name', 'description'];
    }

    public function getConversationTtl(?int $storeId = null): int
    {
        return (int) ($this->getValue('conversational_search/conversation_ttl', $storeId) ?: 86400);
    }

    // Admin AI Assistant
    public function isAdminAssistantEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('admin_assistant/enabled', $storeId);
    }

    public function getAdminAssistantSystemPrompt(?int $storeId = null): string
    {
        return (string) $this->getValue('admin_assistant/system_prompt', $storeId);
    }

    public function getAdminAssistantOpenAiModel(?int $storeId = null): string
    {
        $model = (string) $this->getValue('admin_assistant/openai_model', $storeId);

        if ($model === '') {
            return $this->getOpenAiModel($storeId);
        }

        return $model;
    }

    public function getAdminAssistantConversationTtl(?int $storeId = null): int
    {
        return (int) $this->getValue('admin_assistant/conversation_ttl', $storeId) ?: 86400;
    }

    // Recommendations
    public function isRecommendationsEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->isConversationalSearchEnabled($storeId)
            && $this->getFlag('recommendations/enabled', $storeId);
    }

    public function getRecommendationsLimit(?int $storeId = null): int
    {
        return (int) ($this->getValue('recommendations/limit', $storeId) ?: 8);
    }

    private function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PREFIX . '/' . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    // Only an unset/empty field falls back; an explicit 0 is a valid threshold.
    private function getIntOrDefault(string $path, int $default, ?int $storeId = null): int
    {
        $value = $this->getValue($path, $storeId);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    private function getFlag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PREFIX . '/' . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    // A malformed value (hand-edited, or PHP-serialized from an old install) must not fail every storefront page.
    private function getSerializedRows(string $path, ?int $storeId): mixed
    {
        $value = (string) $this->getValue($path, $storeId);
        if ($value === '') {
            return null;
        }

        try {
            return $this->serializer->unserialize($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
