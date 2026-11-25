<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Conversation\ConversationModelManager;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProviderInterface;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetResolver;
use RunAsRoot\TypeSense\Model\Listing\CategoryPositionSortBuilder;
use RunAsRoot\TypeSense\Model\Listing\FacetConfigBuilder;
use RunAsRoot\TypeSense\Model\Listing\SortOptionProvider;
use RunAsRoot\TypeSense\Model\Search\SearchParametersProvider;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryListingFilterBuilder;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;
use RunAsRoot\TypeSense\ViewModel\Frontend\ListingConfigViewModel;

final class ListingConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private Registry&MockObject $registry;
    private CategoryListingFilterBuilder&MockObject $listingFilterBuilder;
    private SearchRequestParamsBuilder&MockObject $requestParamsBuilder;
    private ProductSchemaProviderInterface&MockObject $schemaProvider;
    private EavConfig&MockObject $eavConfig;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private LoggerInterface&MockObject $logger;
    private CategoryFacetResolver&MockObject $categoryFacetResolver;
    private CategoryPositionSortBuilder&MockObject $positionSortBuilder;
    private SortOptionProvider&MockObject $sortOptionProvider;
    /** @var list<array{label: string, value: string, code: string}> */
    private array $sortOptions = [
        ['label' => 'Relevance', 'value' => '', 'code' => 'position'],
        ['label' => 'Price: Low to High', 'value' => 'price:asc', 'code' => 'price'],
        ['label' => 'Name: A–Z', 'value' => 'name:asc', 'code' => 'name'],
    ];
    /** @var list<mixed> */
    private array $constructorArguments;
    private ListingConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(443);
        $this->config->method('getSearchProtocol')->willReturn('https');
        $this->config->method('getSearchOnlyApiKey')->willReturn('search-only-key');
        $this->sortOptionProvider = $this->createMock(SortOptionProvider::class);
        $this->sortOptionProvider->method('getOptions')->with(1)->willReturnCallback(fn (): array => $this->sortOptions);
        $this->config->method('getProductsPerPage')->willReturn(12);
        $this->config->method('isInStockFirst')->willReturn(true);
        $this->config->method('isDirectMatchesFirstEnabled')->willReturn(true);
        $this->config->method('getListingImageWidth')->willReturn(300);
        $this->config->method('getListingImageHeight')->willReturn(400);
        $this->config->method('getSeoTextSelector')->willReturn('.page-bottom .category-description');

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('de');
        $store->method('getRootCategoryId')->willReturn(2);
        $store->method('getCurrentCurrencyCode')->willReturn('CHF');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $collectionNameResolver->method('resolve')->with('product', 'de', 1)->willReturn('rar_product_de');

        $this->registry = $this->createMock(Registry::class);
        $this->listingFilterBuilder = $this->createMock(CategoryListingFilterBuilder::class);
        $this->requestParamsBuilder = $this->createMock(SearchRequestParamsBuilder::class);
        $this->requestParamsBuilder->method('build')->willReturn(['query_by' => 'name,sku']);
        $searchParametersProvider = $this->createMock(SearchParametersProvider::class);
        $searchParametersProvider->method('getCacheParameters')->willReturn(['use_cache' => true, 'cache_ttl' => 300]);

        $this->schemaProvider = $this->createMock(ProductSchemaProviderInterface::class);
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $route): string => 'https://shop.test/' . $route . '/',
        );
        $localeResolver = $this->createMock(LocaleResolverInterface::class);
        $localeResolver->method('getLocale')->willReturn('de_CH');
        $conversationModelManager = $this->createMock(ConversationModelManager::class);
        $conversationModelManager->method('getModelId')->willReturn('rar-product-assistant');
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->categoryFacetResolver = $this->createMock(CategoryFacetResolver::class);
        $this->positionSortBuilder = $this->createMock(CategoryPositionSortBuilder::class);

        $this->constructorArguments = [
            $this->config,
            $storeManager,
            $collectionNameResolver,
            $this->registry,
            $this->listingFilterBuilder,
            $this->requestParamsBuilder,
            $searchParametersProvider,
            new FacetConfigBuilder($this->config, $this->schemaProvider, $this->eavConfig),
            $this->scopeConfig,
            $urlBuilder,
            $localeResolver,
            $conversationModelManager,
            $this->logger,
            $this->categoryFacetResolver,
            $this->positionSortBuilder,
            $this->sortOptionProvider,
        ];
        $this->sut = new ListingConfigViewModel(...$this->constructorArguments);
    }

    public function test_listing_config_for_category_uses_category_filter_default_sort_and_catalog_limits(): void
    {
        $this->givenCategory(42, 'Gin', 'name');
        $this->listingFilterBuilder->method('build')->with(42, 1)
            ->willReturn('(category_ids:[42]) && visibility:[2,4]');
        $this->requestParamsBuilder->method('getRelevanceSortBy')
            ->with(SearchRequestParamsBuilder::CONTEXT_CATEGORY, 1)
            ->willReturn('_text_match(buckets: 10):desc');
        $this->givenCatalogLimits('12,24,36', '24');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY);

        self::assertSame('category', $config['context']);
        self::assertSame('rar_product_de', $config['productCollection']);
        self::assertSame('(category_ids:[42]) && visibility:[2,4]', $config['baseFilter']);
        self::assertSame('name:asc', $config['defaultSort']);
        self::assertSame([12, 24, 36], $config['limits']);
        self::assertSame(24, $config['perPage']);
        self::assertSame('_text_match(buckets: 10):desc', $config['relevanceSortBy']);
        self::assertFalse($config['directMatchesFirst']);
        self::assertTrue($config['inStockFirst']);
        self::assertSame('Gin', $config['listName']);
        self::assertSame(42, $config['categoryId']);
        self::assertSame('CHF', $config['currencyCode']);
        self::assertSame('de-CH', $config['locale']);
        self::assertSame(300, $config['imageWidth']);
        self::assertSame(400, $config['imageHeight']);
        self::assertSame('https://shop.test/checkout/cart/add/', $config['addToCartUrl']);
        self::assertSame('https://shop.test/wishlist/index/add/', $config['wishlistAddUrl']);
        self::assertTrue($config['showPrices']);
    }

    public function test_category_listing_in_position_order_sorts_by_the_category_position(): void
    {
        $this->givenCategory(42, 'Gin', 'position');
        $this->givenCatalogLimits('12', '12');
        $this->positionSortBuilder->method('build')->with(42, 1, 'rar_product_de')
            ->willReturn('category_position_42:asc');
        $this->requestParamsBuilder->expects(self::never())->method('getRelevanceSortBy');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY);

        self::assertSame('', $config['defaultSort']);
        self::assertSame('category_position_42:asc', $config['relevanceSortBy']);
    }

    public function test_search_listing_never_sorts_by_category_position(): void
    {
        $this->givenCatalogLimits('12', '12');
        $this->positionSortBuilder->expects(self::never())->method('build');
        $this->requestParamsBuilder->method('getRelevanceSortBy')->willReturn(null);

        self::assertNull($this->sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH)['relevanceSortBy']);
    }

    public function test_listing_config_for_search_filters_search_visibility_and_merges_direct_matches(): void
    {
        $this->givenCatalogLimits('12,24,36', '24');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH);

        self::assertSame('search', $config['context']);
        self::assertSame('visibility:[3,4]', $config['baseFilter']);
        self::assertSame('', $config['defaultSort']);
        self::assertSame(12, $config['perPage']);
        self::assertTrue($config['directMatchesFirst']);
        self::assertSame('Search Results', $config['listName']);
        self::assertNull($config['categoryId']);
        self::assertSame(['query_by' => 'name,sku'], $config['requestParams']);
        self::assertSame(['use_cache' => true, 'cache_ttl' => 300], $config['cacheParams']);
    }

    public function test_listing_config_maps_facet_types_with_dropdown_default_and_price_slider(): void
    {
        $this->config->method('getFacetFilters')->willReturn(['categories.lvl0', 'color', 'in_stock', 'price']);
        $this->config->method('getFacetTypes')->willReturn(['color' => ['type' => 'dropdown', 'searchable' => true]]);
        $this->schemaProvider->method('getFields')->willReturn([
            ['name' => 'price', 'type' => 'float', 'facet' => true],
            ['name' => 'in_stock', 'type' => 'bool', 'facet' => true],
            ['name' => 'color', 'type' => 'string', 'facet' => true],
            ['name' => 'categories.lvl0', 'type' => 'string[]', 'facet' => true],
        ]);
        $color = $this->createMock(Attribute::class);
        $color->method('getId')->willReturn(93);
        $color->method('getStoreLabel')->with(1)->willReturn('Farbe');
        $this->eavConfig->method('getAttribute')->willReturn($color);
        $this->givenCatalogLimits('12', '12');

        $facets = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH)['facets'];

        self::assertSame([
            ['field' => 'color', 'label' => 'Farbe', 'type' => 'dropdown', 'searchable' => true, 'boolean' => false, 'numeric' => false],
            ['field' => 'in_stock', 'label' => 'Availability', 'type' => 'dropdown', 'searchable' => false, 'boolean' => true, 'numeric' => false],
            ['field' => 'price', 'label' => 'Price', 'type' => 'slider', 'searchable' => false, 'boolean' => false, 'numeric' => 'float'],
        ], $facets);
    }

    public function test_listing_config_never_contains_admin_api_key(): void
    {
        $this->config->expects(self::never())->method('getApiKey');
        $this->givenCatalogLimits('12', '12');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH);

        self::assertSame([
            'host' => 'search.example.com',
            'port' => 443,
            'protocol' => 'https',
            'apiKey' => 'search-only-key',
        ], $config['connection']);
    }

    public function test_broken_virtual_rule_renders_an_empty_category_listing_instead_of_the_catalog(): void
    {
        $this->givenCategory(7, 'Rum', 'position');
        $this->listingFilterBuilder->method('build')->willThrowException(new \RuntimeException('bad rule'));
        $this->logger->expects(self::once())->method('error');
        $this->givenCatalogLimits('12', '12');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY);

        self::assertSame(ConditionsToFilterByCompiler::NO_MATCH_FILTER, $config['baseFilter']);
        self::assertSame('', $config['defaultSort']);
    }

    public function test_json_config_cannot_close_the_inline_script(): void
    {
        $this->givenCategory(7, '</script><script>alert(1)</script>', 'position');
        $this->listingFilterBuilder->method('build')->willReturn('category_ids:[7]');
        $this->givenCatalogLimits('12', '12');

        $json = $this->sut->getJsonConfig(ListingConfigViewModel::CONTEXT_CATEGORY);

        self::assertStringNotContainsString('</script>', $json);
        self::assertSame('</script><script>alert(1)</script>', json_decode($json, true)['listName']);
    }

    public function test_listing_is_enabled_per_context(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(false);

        self::assertTrue($this->sut->isEnabled(ListingConfigViewModel::CONTEXT_SEARCH));
        self::assertFalse($this->sut->isEnabled(ListingConfigViewModel::CONTEXT_CATEGORY));
    }

    public function test_category_sort_options_are_limited_to_the_category_available_sort_by(): void
    {
        $this->givenCategory(42, 'Gin', 'price', ['price', 'position']);
        $this->givenCatalogLimits('12', '12');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY);

        self::assertSame([
            ['label' => 'Relevance', 'value' => ''],
            ['label' => 'Price: Low to High', 'value' => 'price:asc'],
        ], $config['sortOptions']);
    }

    public function test_category_sort_options_stay_complete_without_or_with_a_disjoint_available_sort_by(): void
    {
        $this->givenCategory(42, 'Gin', 'position', ['rating']);
        $this->givenCatalogLimits('12', '12');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY);

        // An empty sort menu would leave no way to sort at all, so the configured options stay.
        self::assertCount(3, $config['sortOptions']);
        self::assertCount(3, $this->sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH)['sortOptions']);
    }

    public function test_category_with_an_empty_available_sort_by_keeps_every_sort_option(): void
    {
        $this->givenCategory(42, 'Gin', 'position', []);
        $this->givenCatalogLimits('12', '12');

        self::assertSame([
            ['label' => 'Relevance', 'value' => ''],
            ['label' => 'Price: Low to High', 'value' => 'price:asc'],
            ['label' => 'Name: A–Z', 'value' => 'name:asc'],
        ], $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY)['sortOptions']);
    }

    public function test_attribute_sort_option_follows_the_category_available_sort_by_and_default_sort(): void
    {
        $this->sortOptions = [
            ['label' => 'Relevance', 'value' => '', 'code' => 'position'],
            ['label' => 'Alkohol Vol.-%: High to Low', 'value' => 'volume_percent_sort:desc', 'code' => 'volume_percent'],
            ['label' => 'Alkohol Vol.-%: Low to High', 'value' => 'volume_percent_sort:asc', 'code' => 'volume_percent'],
            ['label' => 'Name: A–Z', 'value' => 'name:asc', 'code' => 'name'],
        ];
        $this->givenCategory(42, 'Gin', 'volume_percent', ['position', 'volume_percent']);
        $this->givenCatalogLimits('12', '12');

        $config = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY);

        self::assertSame(['', 'volume_percent_sort:desc', 'volume_percent_sort:asc'], array_column($config['sortOptions'], 'value'));
        self::assertSame('volume_percent_sort:asc', $config['defaultSort']);
    }

    public function test_hidden_prices_remove_the_price_sorts(): void
    {
        $sut = $this->getMockBuilder(ListingConfigViewModel::class)
            ->setConstructorArgs($this->constructorArguments)
            ->onlyMethods(['canShowPrices'])
            ->getMock();
        $sut->method('canShowPrices')->willReturn(false);
        $this->givenCatalogLimits('12', '12');

        $config = $sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH);

        self::assertFalse($config['showPrices']);
        self::assertSame(['', 'name:asc'], array_column($config['sortOptions'], 'value'));
    }

    public function test_category_pages_start_with_the_facet_of_the_category_children_instead_of_the_category_tree_fields(): void
    {
        $category = $this->givenCategory(42, 'Gin', 'position');
        $this->givenColorAndCategoryTreeFacets();
        $categoryFacet = $this->categoryFacet();
        $this->categoryFacetResolver->expects(self::never())->method('resolveForStoreRoot');
        $this->categoryFacetResolver->method('resolve')->with($category, 1)->willReturn($categoryFacet);
        $this->givenCatalogLimits('12', '12');

        $facets = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY)['facets'];

        self::assertSame([$categoryFacet, 'color'], [$facets[0], $facets[1]['field']]);
        self::assertCount(2, $facets);
    }

    public function test_category_without_children_gets_no_category_facet_at_all(): void
    {
        $this->givenCategory(7, 'Kräuter-Schnaps & Likör', 'position');
        $this->givenColorAndCategoryTreeFacets();
        $this->categoryFacetResolver->method('resolve')->willReturn(null);
        $this->givenCatalogLimits('12', '12');

        $facets = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY)['facets'];

        self::assertSame(['color'], array_column($facets, 'field'));
    }

    public function test_category_page_without_a_current_category_gets_no_category_facet(): void
    {
        $this->categoryFacetResolver->expects(self::never())->method('resolve');
        $this->categoryFacetResolver->expects(self::never())->method('resolveForStoreRoot');
        $this->givenCatalogLimits('12', '12');

        self::assertSame([], $this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY)['facets']);
    }

    public function test_search_page_hides_facets_of_attributes_not_filterable_in_search_but_category_pages_keep_them(): void
    {
        $this->givenCategory(42, 'Gin', 'position');
        $this->config->method('getFacetFilters')->willReturn(['color', 'taste']);
        $this->schemaProvider->method('getFields')->willReturn([
            ['name' => 'color', 'type' => 'string[]', 'facet' => true],
            ['name' => 'taste', 'type' => 'string[]', 'facet' => true],
        ]);
        $color = $this->createMock(Attribute::class);
        $color->method('getId')->willReturn(93);
        $color->method('getData')->willReturnMap([['is_filterable_in_search', null, '0']]);
        $taste = $this->createMock(Attribute::class);
        $taste->method('getId')->willReturn(94);
        $taste->method('getData')->willReturnMap([['is_filterable_in_search', null, '1']]);
        $this->eavConfig->method('getAttribute')->willReturnCallback(
            static fn (string $entityType, string $code): Attribute => $code === 'color' ? $color : $taste,
        );
        $this->givenCatalogLimits('12', '12');

        self::assertSame(['taste'], array_column($this->sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH)['facets'], 'field'));
        self::assertSame(
            ['color', 'taste'],
            array_column($this->sut->getConfig(ListingConfigViewModel::CONTEXT_CATEGORY)['facets'], 'field'),
        );
    }

    public function test_search_pages_start_with_the_facet_of_the_top_level_categories(): void
    {
        $this->givenColorAndCategoryTreeFacets();
        $categoryFacet = $this->categoryFacet();
        $this->categoryFacetResolver->expects(self::never())->method('resolve');
        $this->categoryFacetResolver->method('resolveForStoreRoot')->with(2, 1)->willReturn($categoryFacet);
        $this->givenCatalogLimits('12', '12');

        $facets = $this->sut->getConfig(ListingConfigViewModel::CONTEXT_SEARCH)['facets'];

        self::assertSame([$categoryFacet, 'color'], [$facets[0], $facets[1]['field']]);
        self::assertCount(2, $facets);
    }

    public function test_category_names_in_the_json_config_cannot_close_the_inline_script(): void
    {
        $categoryFacet = $this->categoryFacet();
        $categoryFacet['values'] = [['value' => '9', 'label' => '</script><script>alert(1)</script>']];
        $this->categoryFacetResolver->method('resolveForStoreRoot')->willReturn($categoryFacet);
        $this->givenCatalogLimits('12', '12');

        $json = $this->sut->getJsonConfig(ListingConfigViewModel::CONTEXT_SEARCH);

        self::assertStringNotContainsString('</script>', $json);
        self::assertSame($categoryFacet, json_decode($json, true)['facets'][0]);
    }

    private function givenColorAndCategoryTreeFacets(): void
    {
        $this->config->method('getFacetFilters')->willReturn(['categories.lvl0', 'color', 'categories.lvl1']);
        $this->schemaProvider->method('getFields')->willReturn([
            ['name' => 'color', 'type' => 'string', 'facet' => true],
            ['name' => 'category_ids', 'type' => 'int32[]', 'facet' => true],
            ['name' => 'categories.lvl0', 'type' => 'string[]', 'facet' => true],
            ['name' => 'categories.lvl1', 'type' => 'string[]', 'facet' => true],
        ]);
        $this->eavConfig->method('getAttribute')->willReturn($this->createMock(Attribute::class));
    }

    /** @return array<string, mixed> */
    private function categoryFacet(): array
    {
        return [
            'field' => 'category_ids',
            'param' => 'cat',
            'label' => 'Category',
            'type' => 'category',
            'searchable' => false,
            'boolean' => false,
            'numeric' => 'int32',
            'values' => [['value' => '43', 'label' => 'Dry Gin'], ['value' => '44', 'label' => 'Old Tom']],
            'maxValues' => 350,
        ];
    }

    /**
     * @param list<string>|null $availableSortBy
     */
    private function givenCategory(int $id, string $name, string $defaultSortBy, ?array $availableSortBy = null): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn((string) $id);
        $category->method('getName')->willReturn($name);
        $category->method('getDefaultSortBy')->willReturn($defaultSortBy);
        $category->method('getAvailableSortBy')->willReturn($availableSortBy);
        $this->registry->method('registry')->with('current_category')->willReturn($category);

        return $category;
    }

    private function givenCatalogLimits(string $values, string $default): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['catalog/frontend/grid_per_page_values', ScopeInterface::SCOPE_STORE, 1, $values],
            ['catalog/frontend/grid_per_page', ScopeInterface::SCOPE_STORE, 1, $default],
        ]);
    }
}
