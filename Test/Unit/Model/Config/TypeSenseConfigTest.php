<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfig;

final class TypeSenseConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private EncryptorInterface&MockObject $encryptor;
    private TypeSenseConfig $sut;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->sut = new TypeSenseConfig($this->scopeConfig, $this->encryptor, new Json());
    }

    public function test_is_enabled_returns_true_when_config_is_set(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_get_api_key_decrypts_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/general/api_key', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('encrypted_value');

        $this->encryptor->method('decrypt')
            ->with('encrypted_value')
            ->willReturn('actual_api_key');

        self::assertSame('actual_api_key', $this->sut->getApiKey());
    }

    public function test_get_host_returns_config_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/general/host', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('typesense.example.com');

        self::assertSame('typesense.example.com', $this->sut->getHost());
    }

    public function test_get_port_returns_integer(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/general/port', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('8108');

        self::assertSame(8108, $this->sut->getPort());
    }

    public function test_get_collection_name_builds_correct_pattern(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/general/index_prefix', ScopeInterface::SCOPE_STORE, null, 'rar'],
            ]);

        self::assertSame('rar_products_default', $this->sut->getCollectionName('products', 'default'));
    }

    public function test_get_batch_size_returns_integer(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/indexing/batch_size', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('200');

        self::assertSame(200, $this->sut->getBatchSize());
    }

    public function test_get_suggestion_limit_reads_store_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/indexing/suggestion_limit', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn('250');

        self::assertSame(250, $this->sut->getSuggestionLimit(2));
    }

    public function test_get_suggestion_limit_falls_back_to_1000_when_unset_or_not_positive(): void
    {
        $this->scopeConfig->method('getValue')->willReturnOnConsecutiveCalls(null, '0', '-5');

        self::assertSame(1000, $this->sut->getSuggestionLimit(2));
        self::assertSame(1000, $this->sut->getSuggestionLimit(2));
        self::assertSame(1000, $this->sut->getSuggestionLimit(2));
    }

    public function test_suggestion_guards_read_store_values(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['run_as_root_typesense/indexing/suggestion_min_count', ScopeInterface::SCOPE_STORE, 2, '5'],
            ['run_as_root_typesense/indexing/suggestion_max_length', ScopeInterface::SCOPE_STORE, 2, '40'],
            ['run_as_root_typesense/indexing/suggestion_denylist', ScopeInterface::SCOPE_STORE, 2, " Casino \r\n\nviagra\n casino\n  \nÄRGER"],
        ]);

        self::assertSame(5, $this->sut->getSuggestionMinCount(2));
        self::assertSame(40, $this->sut->getSuggestionMaxLength(2));
        self::assertSame(['casino', 'viagra', 'ärger'], $this->sut->getSuggestionDenylist(2));
    }

    public function test_suggestion_guards_fall_back_to_safe_defaults(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame(3, $this->sut->getSuggestionMinCount(2));
        self::assertSame(60, $this->sut->getSuggestionMaxLength(2));
        self::assertSame([], $this->sut->getSuggestionDenylist(2));
    }

    public function test_suggestion_min_count_is_at_least_one(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        self::assertSame(1, $this->sut->getSuggestionMinCount(2));
    }

    public function test_is_conversational_search_enabled_returns_false_when_module_disabled(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(false);

        self::assertFalse($this->sut->isConversationalSearchEnabled());
    }

    public function test_get_embedding_fields_returns_array(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/conversational_search/embedding_fields', ScopeInterface::SCOPE_STORE, null, 'name,description,sku'],
            ]);

        self::assertSame(['name', 'description', 'sku'], $this->sut->getEmbeddingFields());
    }

    public function test_get_embedding_fields_returns_defaults_when_empty(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/conversational_search/embedding_fields', ScopeInterface::SCOPE_STORE, null, ''],
            ]);

        self::assertSame(['name', 'description'], $this->sut->getEmbeddingFields());
    }

    public function test_is_recommendations_enabled_returns_true_when_all_conditions_met(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnMap([
                ['run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null, true],
                ['run_as_root_typesense/recommendations/enabled', ScopeInterface::SCOPE_STORE, null, true],
            ]);

        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/conversational_search/enabled', ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        self::assertTrue($this->sut->isRecommendationsEnabled());
    }

    public function test_is_recommendations_enabled_returns_false_when_conversational_search_disabled(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnMap([
                ['run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null, true],
                ['run_as_root_typesense/recommendations/enabled', ScopeInterface::SCOPE_STORE, null, true],
            ]);

        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/conversational_search/enabled', ScopeInterface::SCOPE_STORE, null, '0'],
            ]);

        self::assertFalse($this->sut->isRecommendationsEnabled());
    }

    public function test_get_recommendations_limit_returns_configured_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/recommendations/limit', ScopeInterface::SCOPE_STORE, null, '12'],
            ]);

        self::assertSame(12, $this->sut->getRecommendationsLimit());
    }

    public function test_get_recommendations_limit_returns_default_when_empty(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/recommendations/limit', ScopeInterface::SCOPE_STORE, null, ''],
            ]);

        self::assertSame(8, $this->sut->getRecommendationsLimit());
    }

    public function test_is_admin_assistant_enabled_returns_true_when_config_is_set(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/admin_assistant/enabled', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        self::assertTrue($this->sut->isAdminAssistantEnabled());
    }

    public function test_is_admin_assistant_enabled_returns_false_when_config_not_set(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/admin_assistant/enabled', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(false);

        self::assertFalse($this->sut->isAdminAssistantEnabled());
    }

    public function test_get_admin_assistant_system_prompt_returns_configured_value(): void
    {
        $expectedPrompt = 'You are a Magento store analytics assistant.';

        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/admin_assistant/system_prompt', ScopeInterface::SCOPE_STORE, null, $expectedPrompt],
            ]);

        self::assertSame($expectedPrompt, $this->sut->getAdminAssistantSystemPrompt());
    }

    public function test_get_admin_assistant_openai_model_returns_configured_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/admin_assistant/openai_model', ScopeInterface::SCOPE_STORE, null, 'openai/gpt-4o'],
            ]);

        self::assertSame('openai/gpt-4o', $this->sut->getAdminAssistantOpenAiModel());
    }

    public function test_get_admin_assistant_openai_model_falls_back_to_conversational_search_model_when_empty(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/admin_assistant/openai_model', ScopeInterface::SCOPE_STORE, null, ''],
                ['run_as_root_typesense/conversational_search/openai_model', ScopeInterface::SCOPE_STORE, null, 'openai/gpt-4o-mini'],
            ]);

        self::assertSame('openai/gpt-4o-mini', $this->sut->getAdminAssistantOpenAiModel());
    }

    public function test_get_admin_assistant_openai_model_falls_back_to_default_when_both_empty(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/admin_assistant/openai_model', ScopeInterface::SCOPE_STORE, null, ''],
                ['run_as_root_typesense/conversational_search/openai_model', ScopeInterface::SCOPE_STORE, null, ''],
            ]);

        self::assertSame('openai/gpt-4o-mini', $this->sut->getAdminAssistantOpenAiModel());
    }

    public function test_get_admin_assistant_conversation_ttl_returns_configured_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/admin_assistant/conversation_ttl', ScopeInterface::SCOPE_STORE, null, '3600'],
            ]);

        self::assertSame(3600, $this->sut->getAdminAssistantConversationTtl());
    }

    public function test_get_admin_assistant_conversation_ttl_returns_integer(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/admin_assistant/conversation_ttl', ScopeInterface::SCOPE_STORE, null, '86400'],
            ]);

        self::assertSame(86400, $this->sut->getAdminAssistantConversationTtl());
    }

    public function test_search_parameter_getters_cast_configured_values(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/search/text_match_type', ScopeInterface::SCOPE_STORE, 2, 'sum_score'],
                ['run_as_root_typesense/search/drop_tokens_threshold', ScopeInterface::SCOPE_STORE, 2, '3'],
                ['run_as_root_typesense/search/typo_tokens_threshold', ScopeInterface::SCOPE_STORE, 2, '0'],
                ['run_as_root_typesense/search/min_len_1typo', ScopeInterface::SCOPE_STORE, 2, '4'],
                ['run_as_root_typesense/search/min_len_2typo', ScopeInterface::SCOPE_STORE, 2, '7'],
                ['run_as_root_typesense/search/prioritize_exact_match', ScopeInterface::SCOPE_STORE, 2, '0'],
                ['run_as_root_typesense/search/split_join_tokens', ScopeInterface::SCOPE_STORE, 2, 'off'],
                ['run_as_root_typesense/search/cache_ttl', ScopeInterface::SCOPE_STORE, 2, '600'],
            ]);

        self::assertSame('sum_score', $this->sut->getTextMatchType(2));
        self::assertSame(3, $this->sut->getDropTokensThreshold(2));
        self::assertSame(0, $this->sut->getTypoTokensThreshold(2));
        self::assertSame(4, $this->sut->getMinLen1Typo(2));
        self::assertSame(7, $this->sut->getMinLen2Typo(2));
        self::assertFalse($this->sut->isPrioritizeExactMatch(2));
        self::assertSame('off', $this->sut->getSplitJoinTokens(2));
        self::assertSame(600, $this->sut->getSearchCacheTtl(2));
    }

    public function test_search_parameter_getters_fall_back_to_defaults_when_empty(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('max_weight', $this->sut->getTextMatchType());
        self::assertSame(0, $this->sut->getDropTokensThreshold());
        self::assertSame(1, $this->sut->getTypoTokensThreshold());
        self::assertSame(5, $this->sut->getMinLen1Typo());
        self::assertSame(8, $this->sut->getMinLen2Typo());
        self::assertTrue($this->sut->isPrioritizeExactMatch());
        self::assertSame('fallback', $this->sut->getSplitJoinTokens());
        self::assertSame(300, $this->sut->getSearchCacheTtl());
    }

    public function test_boost_text_match_buckets_reads_search_group(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/search/boost_text_match_buckets', ScopeInterface::SCOPE_STORE, 2, '4'],
            ]);

        self::assertSame(4, $this->sut->getBoostTextMatchBuckets(2));
    }

    #[DataProvider('invalidBucketsProvider')]
    public function test_boost_text_match_buckets_falls_back_to_ten(?string $value): void
    {
        $this->scopeConfig->method('getValue')->willReturn($value);

        self::assertSame(10, $this->sut->getBoostTextMatchBuckets());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidBucketsProvider(): array
    {
        return ['unset' => [null], 'empty' => [''], 'zero' => ['0'], 'negative' => ['-3']];
    }

    public function test_prefix_search_and_cache_flags_read_search_group(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnMap([
                ['run_as_root_typesense/search/prefix_search', ScopeInterface::SCOPE_STORE, 1, true],
                ['run_as_root_typesense/search/use_cache', ScopeInterface::SCOPE_STORE, 1, false],
            ]);

        self::assertTrue($this->sut->isPrefixSearchEnabled(1));
        self::assertFalse($this->sut->isSearchCacheEnabled(1));
    }

    public function test_infix_and_stem_getters_read_search_group(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnMap([
                ['run_as_root_typesense/search/infix_enabled', ScopeInterface::SCOPE_STORE, 1, true],
                ['run_as_root_typesense/search/stem_enabled', ScopeInterface::SCOPE_STORE, 1, false],
            ]);
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/search/infix_fields', ScopeInterface::SCOPE_STORE, 1, 'name,sku'],
                ['run_as_root_typesense/search/infix_mode', ScopeInterface::SCOPE_STORE, 1, 'fallback'],
            ]);

        self::assertTrue($this->sut->isInfixEnabled(1));
        self::assertFalse($this->sut->isStemEnabled(1));
        self::assertSame(['name', 'sku'], $this->sut->getInfixFields(1));
        self::assertSame('fallback', $this->sut->getInfixMode(1));
    }

    public function test_infix_fields_empty_and_mode_defaults_to_always(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame([], $this->sut->getInfixFields());
        self::assertSame('always', $this->sut->getInfixMode());
    }

    public function test_decompounding_getters_read_search_group_per_store(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/search/german_decompounding', ScopeInterface::SCOPE_STORE, 4)
            ->willReturn(true);
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/search/decompound_skip_attribute', ScopeInterface::SCOPE_STORE, 4)
            ->willReturn('brand_line');

        self::assertTrue($this->sut->isGermanDecompoundingEnabled(4));
        self::assertSame('brand_line', $this->sut->getDecompoundSkipAttribute(4));
    }

    public function test_decompound_skip_attribute_is_empty_when_unset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->sut->getDecompoundSkipAttribute());
    }

    public function test_get_searchable_attributes_decodes_rows_in_saved_order(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/search/searchable_attributes', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('{"_1":{"attribute":"name","weight":"10"},"_2":{"attribute":"","weight":"5"},"_3":{"attribute":"sku"}}');

        self::assertSame(
            [['attribute' => 'name', 'weight' => 10], ['attribute' => 'sku', 'weight' => 1]],
            $this->sut->getSearchableAttributes(3),
        );
    }

    public function test_get_searchable_attributes_is_empty_when_unset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame([], $this->sut->getSearchableAttributes());
    }

    public function test_direct_matches_first_reads_search_group_per_store(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/search/direct_matches_first', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn(true);

        self::assertTrue($this->sut->isDirectMatchesFirstEnabled(3));
    }

    public function test_cacheable_search_page_reads_instant_search_group_per_store(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/instant_search/cacheable_search_page', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn(true);

        self::assertTrue($this->sut->isCacheableSearchPageEnabled(2));
    }

    public function test_get_listing_image_id_returns_configured_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/instant_search/image_type', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('category_page_list');

        self::assertSame('category_page_list', $this->sut->getListingImageId());
    }

    public function test_get_listing_image_id_falls_back_to_category_page_grid(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('category_page_grid', $this->sut->getListingImageId());
    }

    public function test_get_listing_image_id_falls_back_for_legacy_value(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('small_image');

        self::assertSame('category_page_grid', $this->sut->getListingImageId());
    }

    public function test_listing_settings_read_instant_search_group_per_store(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/instant_search/in_stock_first', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['run_as_root_typesense/instant_search/no_results_category_id', ScopeInterface::SCOPE_STORE, 2, ' 17 '],
            ['run_as_root_typesense/instant_search/seo_text_selector', ScopeInterface::SCOPE_STORE, 2, ' .page-bottom .seo '],
            ['run_as_root_typesense/instant_search/image_width', ScopeInterface::SCOPE_STORE, 2, '240'],
            ['run_as_root_typesense/instant_search/image_height', ScopeInterface::SCOPE_STORE, 2, '300'],
        ]);

        self::assertTrue($this->sut->isInStockFirst(2));
        self::assertSame(17, $this->sut->getNoResultsCategoryId(2));
        self::assertSame('.page-bottom .seo', $this->sut->getSeoTextSelector(2));
        self::assertSame(240, $this->sut->getListingImageWidth(2));
        self::assertSame(300, $this->sut->getListingImageHeight(2));
    }

    public function test_empty_listing_settings_disable_trending_products_and_seo_text_hiding(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertNull($this->sut->getNoResultsCategoryId());
        self::assertSame('', $this->sut->getSeoTextSelector());
        self::assertSame(0, $this->sut->getListingImageWidth());
        self::assertSame([], $this->sut->getFacetTypes());
    }

    public function test_malformed_serialized_rows_are_treated_as_unset(): void
    {
        // A hand-edited or legacy (PHP-serialized) value must not fail every listing and search page.
        $this->scopeConfig->method('getValue')->willReturn('a:1:{i:0;s:5:"color";}');

        self::assertSame([], $this->sut->getFacetTypes());
        self::assertSame([], $this->sut->getSearchableAttributes());
    }

    public function test_facet_filters_are_the_configured_fields_without_a_forced_category_field(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/instant_search/facet_filters', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn(' color ,,in_stock,color');

        self::assertSame(['color', 'in_stock'], $this->sut->getFacetFilters(2));
    }

    public function test_no_facet_filters_configured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame([], $this->sut->getFacetFilters());
    }

    public function test_facet_types_are_keyed_by_attribute_and_skip_incomplete_rows(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/instant_search/facet_types', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(json_encode([
                '_1' => ['attribute' => 'color', 'type' => 'dropdown', 'searchable' => '1'],
                '_2' => ['attribute' => 'in_stock', 'type' => 'checkbox', 'searchable' => '0'],
                '_3' => ['attribute' => '', 'type' => 'slider'],
                '_4' => ['attribute' => 'size', 'type' => 'bogus'],
            ]));

        self::assertSame([
            'color' => ['type' => 'dropdown', 'searchable' => true],
            'in_stock' => ['type' => 'checkbox', 'searchable' => false],
            'size' => ['type' => 'dropdown', 'searchable' => false],
        ], $this->sut->getFacetTypes());
    }

    public function test_autocomplete_section_counts_and_group_by_field(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['run_as_root_typesense/autocomplete/category_count', ScopeInterface::SCOPE_STORE, 2, '3'],
            ['run_as_root_typesense/autocomplete/cms_page_count', ScopeInterface::SCOPE_STORE, 2, '2'],
            ['run_as_root_typesense/autocomplete/suggestion_count', ScopeInterface::SCOPE_STORE, 2, '4'],
            ['run_as_root_typesense/autocomplete/group_by', ScopeInterface::SCOPE_STORE, 2, ' variant_group '],
        ]);

        self::assertSame(3, $this->sut->getAutocompleteCategoryCount(2));
        self::assertSame(2, $this->sut->getAutocompleteCmsPageCount(2));
        self::assertSame(4, $this->sut->getAutocompleteSuggestionCount(2));
        self::assertSame('variant_group', $this->sut->getAutocompleteGroupBy(2));
    }

    public function test_empty_or_negative_autocomplete_counts_are_zero(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['run_as_root_typesense/autocomplete/category_count', ScopeInterface::SCOPE_STORE, null, null],
            ['run_as_root_typesense/autocomplete/cms_page_count', ScopeInterface::SCOPE_STORE, null, '-1'],
        ]);

        self::assertSame(0, $this->sut->getAutocompleteCategoryCount());
        self::assertSame(0, $this->sut->getAutocompleteCmsPageCount());
        self::assertSame('', $this->sut->getAutocompleteGroupBy());
    }

    public function test_autocomplete_cross_sell_popular_and_meta_settings_read_autocomplete_group(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/autocomplete/crosssell_enabled', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['run_as_root_typesense/autocomplete/crosssell_count', ScopeInterface::SCOPE_STORE, 2, '4'],
            ['run_as_root_typesense/autocomplete/crosssell_fallback_attribute', ScopeInterface::SCOPE_STORE, 2, ' taste '],
            ['run_as_root_typesense/autocomplete/crosssell_fallback_sort', ScopeInterface::SCOPE_STORE, 2, ' sales_count:desc '],
            ['run_as_root_typesense/autocomplete/crosssell_meta_fields', ScopeInterface::SCOPE_STORE, 2, 'trade_name, taste'],
            ['run_as_root_typesense/autocomplete/product_meta_fields', ScopeInterface::SCOPE_STORE, 2, ' categories.lvl1 ,volume_percent,,taste'],
            ['run_as_root_typesense/autocomplete/popular_search_count', ScopeInterface::SCOPE_STORE, 2, '6'],
        ]);

        self::assertTrue($this->sut->isAutocompleteCrossSellEnabled(2));
        self::assertSame(4, $this->sut->getAutocompleteCrossSellCount(2));
        self::assertSame('taste', $this->sut->getAutocompleteCrossSellFallbackAttribute(2));
        self::assertSame('sales_count:desc', $this->sut->getAutocompleteCrossSellFallbackSort(2));
        self::assertSame(['trade_name', 'taste'], $this->sut->getAutocompleteCrossSellMetaFields(2));
        self::assertSame(['categories.lvl1', 'volume_percent', 'taste'], $this->sut->getAutocompleteProductMetaFields(2));
        self::assertSame(6, $this->sut->getAutocompletePopularSearchCount(2));
    }

    public function test_unset_autocomplete_cross_sell_popular_and_meta_settings_turn_the_features_off(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['run_as_root_typesense/autocomplete/crosssell_count', ScopeInterface::SCOPE_STORE, null, '-2'],
            ['run_as_root_typesense/autocomplete/popular_search_count', ScopeInterface::SCOPE_STORE, null, '-1'],
        ]);

        self::assertFalse($this->sut->isAutocompleteCrossSellEnabled());
        self::assertSame(0, $this->sut->getAutocompleteCrossSellCount());
        self::assertSame('', $this->sut->getAutocompleteCrossSellFallbackAttribute());
        self::assertSame('', $this->sut->getAutocompleteCrossSellFallbackSort());
        self::assertSame([], $this->sut->getAutocompleteCrossSellMetaFields());
        self::assertSame([], $this->sut->getAutocompleteProductMetaFields());
        self::assertSame(0, $this->sut->getAutocompletePopularSearchCount());
    }

    public function test_entity_indexing_reads_the_entity_type_flag(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['run_as_root_typesense/indexing/customer_enabled', ScopeInterface::SCOPE_STORE, 3, '0'],
            ['run_as_root_typesense/indexing/order_enabled', ScopeInterface::SCOPE_STORE, 3, '1'],
        ]);

        self::assertFalse($this->sut->isEntityIndexingEnabled('customer', 3));
        self::assertTrue($this->sut->isEntityIndexingEnabled('order', 3));
    }

    public function test_entity_type_without_a_flag_stays_indexed(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertTrue($this->sut->isEntityIndexingEnabled('third_party_entity', 3));
    }

    public function test_exhaustive_search_reads_the_search_flag(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/search/exhaustive_search', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn(true);

        self::assertTrue($this->sut->isExhaustiveSearchEnabled(2));
    }
}
