<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Search\Helper\Data as SearchHelper;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProviderInterface;
use RunAsRoot\TypeSense\Model\Search\SearchParametersProvider;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;
use RunAsRoot\TypeSense\ViewModel\Frontend\AutocompleteConfigViewModel;

final class AutocompleteConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private Store&MockObject $store;
    private SearchRequestParamsBuilder&MockObject $requestParamsBuilder;
    private SearchParametersProvider&MockObject $searchParametersProvider;
    private SearchHelper&MockObject $searchHelper;
    private ProductSchemaProviderInterface&MockObject $schemaProvider;
    private AutocompleteConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config                = $this->createMock(TypeSenseConfigInterface::class);
        $this->storeManager          = $this->createMock(StoreManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);

        $this->store = $this->createMock(Store::class);
        $this->store->method('getCode')->willReturn('default');
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getBaseUrl')->willReturn('https://shop.test/de/');
        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->requestParamsBuilder = $this->createMock(SearchRequestParamsBuilder::class);
        $this->searchParametersProvider = $this->createMock(SearchParametersProvider::class);
        $this->searchHelper = $this->createMock(SearchHelper::class);
        $this->schemaProvider = $this->createMock(ProductSchemaProviderInterface::class);

        $this->sut = new AutocompleteConfigViewModel(
            $this->config,
            $this->storeManager,
            $this->collectionNameResolver,
            $this->requestParamsBuilder,
            $this->searchParametersProvider,
            $this->searchHelper,
            $this->schemaProvider,
        );
    }

    public function test_is_enabled_returns_true_when_both_module_and_autocomplete_are_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAutocompleteEnabled')->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_module_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isAutocompleteEnabled')->willReturn(true);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_autocomplete_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAutocompleteEnabled')->willReturn(false);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_autocomplete_config_has_min_query_length_collections_and_no_admin_key(): void
    {
        $this->config->method('getApiKey')->willReturn('admin-secret-key');
        $this->config->method('getSearchOnlyApiKey')->willReturn('search-only-key');
        $this->config->method('getAutocompleteProductCount')->willReturn(6);
        $this->config->method('getAutocompleteCategoryCount')->willReturn(3);
        $this->config->method('getAutocompleteCmsPageCount')->willReturn(2);
        $this->config->method('getAutocompleteSuggestionCount')->willReturn(4);
        $this->config->method('isCategoryIndexingEnabled')->willReturn(true);
        $this->config->method('isCmsPageIndexingEnabled')->willReturn(true);
        $this->config->method('isSuggestionIndexingEnabled')->willReturn(true);
        $this->searchHelper->method('getMinQueryLength')->willReturn('3');
        $this->searchHelper->method('getResultUrl')->willReturn('https://shop.test/de/catalogsearch/result/');
        $this->collectionNameResolver
            ->method('resolve')
            ->willReturnMap([
                ['product', 'default', 1, 'prefix_product_default'],
                ['category', 'default', 1, 'prefix_category_default'],
                ['cms_page', 'default', 1, 'prefix_cms_page_default'],
                ['suggestion', 'default', 1, 'prefix_suggestion_default'],
            ]);

        $result = $this->sut->getConfig();

        self::assertSame(3, $result['minQueryLength']);
        self::assertSame('prefix_product_default', $result['productCollection']);
        self::assertSame('prefix_category_default', $result['categoryCollection']);
        self::assertSame('prefix_cms_page_default', $result['pageCollection']);
        self::assertSame('prefix_suggestion_default', $result['suggestionCollection']);
        self::assertSame(6, $result['productCount']);
        self::assertSame(3, $result['categoryCount']);
        self::assertSame(2, $result['pageCount']);
        self::assertSame(4, $result['suggestionCount']);
        self::assertSame('https://shop.test/de/catalogsearch/result/', $result['searchUrl']);
        self::assertSame('https://shop.test/de/', $result['baseUrl']);

        // The shared client (js/client-loader.phtml) holds the search-only key; this config needs none.
        $json = $this->sut->getJsonConfig();
        self::assertStringNotContainsString('admin-secret-key', $json);
        self::assertStringNotContainsString('search-only-key', $json);
        self::assertStringNotContainsStringIgnoringCase('apikey', $json);
    }

    public function test_sections_without_an_index_or_a_count_have_no_collection(): void
    {
        $this->config->method('getAutocompleteCategoryCount')->willReturn(0);
        $this->config->method('getAutocompleteCmsPageCount')->willReturn(2);
        $this->config->method('getAutocompleteSuggestionCount')->willReturn(4);
        $this->config->method('isCategoryIndexingEnabled')->willReturn(true);
        $this->config->method('isCmsPageIndexingEnabled')->willReturn(false);
        $this->config->method('isSuggestionIndexingEnabled')->willReturn(true);
        $this->collectionNameResolver->method('resolve')->willReturn('collection');

        $result = $this->sut->getConfig();

        self::assertNull($result['categoryCollection']);
        self::assertNull($result['pageCollection']);
        self::assertSame('collection', $result['suggestionCollection']);
    }

    public function test_group_by_is_used_only_for_a_faceted_schema_field(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $this->schemaProvider->method('getFields')->willReturn([
            ['name' => 'variant_group', 'type' => 'string', 'facet' => true],
            ['name' => 'name', 'type' => 'string'],
        ]);
        $groupBy = 'variant_group';
        $this->config->method('getAutocompleteGroupBy')->willReturnCallback(
            static function () use (&$groupBy): string {
                return $groupBy;
            },
        );

        self::assertSame('variant_group', $this->sut->getConfig()['groupBy']);

        $groupBy = 'name';
        self::assertNull($this->sut->getConfig()['groupBy']);

        $groupBy = 'missing_field';
        self::assertNull($this->sut->getConfig()['groupBy']);

        $groupBy = '';
        self::assertNull($this->sut->getConfig()['groupBy']);
    }

    public function test_config_carries_highlighting_and_price_visibility(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $this->config->method('isHighlightEnabled')->with(1)->willReturn(true);

        $result = $this->sut->getConfig();

        self::assertTrue($result['highlight']);
        self::assertTrue($result['showPrices']);
    }

    public function test_config_carries_the_product_fields_the_dropdown_reads(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');

        self::assertSame(
            ['id', 'name', 'sku', 'url', 'image_url', 'final_price', 'price'],
            $this->sut->getConfig()['productFields'],
        );
    }

    public function test_product_fields_follow_get_product_fields(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $sut = $this->getMockBuilder(AutocompleteConfigViewModel::class)
            ->setConstructorArgs([
                $this->config,
                $this->storeManager,
                $this->collectionNameResolver,
                $this->requestParamsBuilder,
                $this->searchParametersProvider,
                $this->searchHelper,
                $this->schemaProvider,
            ])
            ->onlyMethods(['getProductFields'])
            ->getMock();
        $sut->method('getProductFields')->willReturn(['id', 'name', 'brand']);

        self::assertSame(['id', 'name', 'brand'], $sut->getConfig()['productFields']);
    }

    public function test_search_form_values_come_from_magento_search_settings(): void
    {
        $this->searchHelper->method('getMinQueryLength')->willReturn('2');
        $this->searchHelper->method('getMaxQueryLength')->willReturn('128');
        $this->searchHelper->method('getResultUrl')->willReturn('https://shop.test/catalogsearch/result/');
        $this->searchHelper->method('getQueryParamName')->willReturn('q');
        $this->searchHelper->method('getEscapedQueryText')->willReturn('&lt;b&gt;');

        self::assertSame(2, $this->sut->getMinQueryLength());
        self::assertSame(128, $this->sut->getMaxQueryLength());
        self::assertSame('https://shop.test/catalogsearch/result/', $this->sut->getResultUrl());
        self::assertSame('q', $this->sut->getQueryParamName());
        self::assertSame('&lt;b&gt;', $this->sut->getEscapedQueryText());
    }

    public function test_get_json_config_returns_valid_json_string(): void
    {
        $this->config->method('getAutocompleteProductCount')->willReturn(4);

        $this->collectionNameResolver->method('resolve')->willReturn('collection_name');

        $json = $this->sut->getJsonConfig();

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame(4, $decoded['productCount']);
    }

    public function test_config_contains_request_and_cache_params(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $this->requestParamsBuilder->method('build')
            ->with(SearchRequestParamsBuilder::CONTEXT_AUTOCOMPLETE, 1)
            ->willReturn(['query_by' => 'name,sku,description', 'num_typos' => 2]);
        $this->requestParamsBuilder->method('getRelevanceSortBy')
            ->with(SearchRequestParamsBuilder::CONTEXT_AUTOCOMPLETE, 1)
            ->willReturn(null);
        $this->searchParametersProvider->method('getCacheParameters')
            ->with(1)
            ->willReturn(['use_cache' => true, 'cache_ttl' => 300]);

        $result = $this->sut->getConfig();

        self::assertSame(['query_by' => 'name,sku,description', 'num_typos' => 2], $result['requestParams']);
        self::assertSame(['use_cache' => true, 'cache_ttl' => 300], $result['cacheParams']);
        self::assertArrayHasKey('relevanceSortBy', $result);
        self::assertNull($result['relevanceSortBy']);
    }

    public function test_view_models_expose_direct_matches_flag(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $this->config->method('isDirectMatchesFirstEnabled')->with(1)->willReturn(true);

        $result = $this->sut->getConfig();

        self::assertTrue($result['directMatchesFirst']);
    }

    public function test_json_config_escapes_markup_in_admin_supplied_values(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $sortBy = "_eval([(name:=`</script><!--<script>a & 'b' \"c\"`):5]):desc";
        $this->requestParamsBuilder->method('getRelevanceSortBy')->willReturn($sortBy);

        $json = $this->sut->getJsonConfig();

        foreach (['<', '>', '&', "'"] as $character) {
            self::assertStringNotContainsString($character, $json);
        }
        self::assertSame($sortBy, json_decode($json, true)['relevanceSortBy']);
    }

    public function test_cross_sells_meta_lines_and_popular_searches_are_configured_per_store(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $this->schemaProvider->method('getFields')->willReturn([
            ['name' => 'taste', 'type' => 'string[]', 'facet' => true],
        ]);
        $this->config->method('isSuggestionIndexingEnabled')->willReturn(true);
        $this->config->method('getAutocompleteSuggestionCount')->willReturn(4);
        $this->config->method('getAutocompletePopularSearchCount')->with(1)->willReturn(6);
        $this->config->method('getAutocompleteProductMetaFields')->with(1)
            ->willReturn(['categories.lvl1', 'volume_percent', 'taste', 'bad field']);
        $this->config->method('isAutocompleteCrossSellEnabled')->with(1)->willReturn(true);
        $this->config->method('getAutocompleteCrossSellCount')->with(1)->willReturn(4);
        $this->config->method('getAutocompleteCrossSellFallbackAttribute')->with(1)->willReturn('taste');
        $this->config->method('getAutocompleteCrossSellFallbackSort')->with(1)->willReturn('sales_count:desc');
        $this->config->method('getAutocompleteCrossSellMetaFields')->with(1)->willReturn(['trade_name', 'taste']);

        $result = $this->sut->getConfig();

        self::assertSame(['categories.lvl1', 'volume_percent', 'taste'], $result['productMetaFields']);
        self::assertSame(4, $result['crossSellCount']);
        self::assertSame('taste', $result['crossSellFallbackField']);
        self::assertSame('sales_count:desc', $result['crossSellFallbackSortBy']);
        self::assertSame(['trade_name', 'taste'], $result['crossSellMetaFields']);
        self::assertSame(6, $result['popularSearchCount']);
        self::assertSame(
            ['id', 'name', 'sku', 'url', 'image_url', 'final_price', 'price', 'categories.lvl1', 'volume_percent',
                'taste', 'crosssell_ids', 'trade_name'],
            $result['productFields'],
        );
    }

    public function test_disabled_cross_sells_and_a_missing_suggestion_index_send_no_extra_searches(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $this->config->method('isSuggestionIndexingEnabled')->willReturn(false);
        $this->config->method('getAutocompletePopularSearchCount')->willReturn(6);
        $this->config->method('isAutocompleteCrossSellEnabled')->willReturn(false);
        $this->config->method('getAutocompleteCrossSellCount')->willReturn(4);
        $this->config->method('getAutocompleteCrossSellFallbackAttribute')->willReturn('taste');

        $result = $this->sut->getConfig();

        self::assertSame(0, $result['crossSellCount']);
        self::assertSame(0, $result['popularSearchCount']);
        self::assertSame([], $result['productMetaFields']);
        self::assertSame(['id', 'name', 'sku', 'url', 'image_url', 'final_price', 'price'], $result['productFields']);
    }

    public function test_cross_sell_fallback_needs_a_schema_field_and_a_sort(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturn('collection');
        $this->schemaProvider->method('getFields')->willReturn([['name' => 'color', 'type' => 'string[]']]);
        $this->config->method('isAutocompleteCrossSellEnabled')->willReturn(true);
        $this->config->method('getAutocompleteCrossSellCount')->willReturn(4);
        $this->config->method('getAutocompleteCrossSellFallbackAttribute')->willReturn('missing_field');
        $this->config->method('getAutocompleteCrossSellFallbackSort')->willReturn('');

        $result = $this->sut->getConfig();

        self::assertSame(4, $result['crossSellCount']);
        self::assertNull($result['crossSellFallbackField']);
        self::assertNull($result['crossSellFallbackSortBy']);
        self::assertContains('crosssell_ids', $result['productFields']);
    }
}
