<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\AttributeResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\CategoryResolverInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\CatalogWordsProvider;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\CompoundTailExtractor;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\NameDecompounder;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\SplitterProvider;
use RunAsRoot\TypeSense\Model\Indexer\Product\ImageResolverInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\PriceCalculatorInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductDataBuilder;
use RunAsRoot\TypeSense\Model\Indexer\Product\ReviewResolverInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\SalesCountResolverInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\StockResolverInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\UrlResolverInterface;

final class ProductDataBuilderTest extends TestCase
{
    private AttributeResolverInterface&MockObject $attributeResolver;
    private PriceCalculatorInterface&MockObject $priceCalculator;
    private ImageResolverInterface&MockObject $imageResolver;
    private StockResolverInterface&MockObject $stockResolver;
    private CategoryResolverInterface&MockObject $categoryResolver;
    private UrlResolverInterface&MockObject $urlResolver;
    private ProductCollectionFactory&MockObject $collectionFactory;
    private SalesCountResolverInterface&MockObject $salesCountResolver;
    private ReviewResolverInterface&MockObject $reviewResolver;
    private StoreManagerInterface&MockObject $storeManager;
    private TypeSenseConfigInterface&MockObject $config;
    private SplitterProvider&MockObject $splitterProvider;
    private CatalogWordsProvider&MockObject $catalogWordsProvider;
    private NameDecompounder $nameDecompounder;
    private ProductDataBuilder $sut;

    protected function setUp(): void
    {
        $this->attributeResolver = $this->createMock(AttributeResolverInterface::class);
        $this->priceCalculator = $this->createMock(PriceCalculatorInterface::class);
        $this->imageResolver = $this->createMock(ImageResolverInterface::class);
        $this->stockResolver = $this->createMock(StockResolverInterface::class);
        $this->categoryResolver = $this->createMock(CategoryResolverInterface::class);
        $this->urlResolver = $this->createMock(UrlResolverInterface::class);
        $this->collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->salesCountResolver = $this->createMock(SalesCountResolverInterface::class);
        $this->salesCountResolver->method('getSalesCount')->willReturn(0);

        $this->reviewResolver = $this->createMock(ReviewResolverInterface::class);
        $this->reviewResolver->method('getRatingSummary')->willReturn(0);
        $this->reviewResolver->method('getReviewCount')->willReturn(0);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->splitterProvider = $this->createMock(SplitterProvider::class);
        $this->catalogWordsProvider = $this->createMock(CatalogWordsProvider::class);
        $this->nameDecompounder = new NameDecompounder(
            new CompoundTailExtractor($this->splitterProvider),
            $this->catalogWordsProvider,
        );

        $this->sut = $this->buildSut();
    }

    public function test_build_returns_document_with_core_fields(): void
    {
        $product = $this->createProductMock(
            id: 42,
            sku: 'TEST-SKU-001',
            name: 'Test Product',
            typeId: 'simple',
            visibility: 4,
            createdAt: '2024-01-15 10:00:00',
            updatedAt: '2024-06-20 15:30:00',
        );

        $this->priceCalculator->method('getFinalPrice')->with($product)->willReturn(99.99);
        $this->priceCalculator->method('getSpecialPrice')->with($product)->willReturn(null);
        $this->imageResolver->method('getImageUrl')->with($product)->willReturn('https://example.com/image.jpg');
        $this->stockResolver->method('isInStock')->with($product)->willReturn(true);
        $this->urlResolver->method('getProductUrl')->with($product)->willReturn('https://example.com/test-product.html');
        $this->categoryResolver->method('getCategoryData')->with($product)->willReturn([
            'categories' => ['Electronics', 'Phones'],
            'category_ids' => [3, 7],
            'categories.lvl0' => ['Electronics'],
            'categories.lvl1' => ['Electronics > Phones'],
            'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->with($product)->willReturn([]);

        $product->method('getData')->willReturnMap([
            ['description', null, 'A detailed description'],
            ['short_description', null, 'A short description'],
        ]);

        $document = $this->sut->build($product, 1);

        self::assertSame('42', $document['id']);
        self::assertSame(42, $document['product_id']);
        self::assertSame('TEST-SKU-001', $document['sku']);
        self::assertSame('Test Product', $document['name']);
        self::assertSame('simple', $document['type_id']);
        self::assertSame(4, $document['visibility']);
        self::assertSame(99.99, $document['price']);
        self::assertTrue($document['in_stock']);
        self::assertSame('https://example.com/image.jpg', $document['image_url']);
        self::assertSame('https://example.com/test-product.html', $document['url']);
        self::assertSame(['Electronics', 'Phones'], $document['categories']);
        self::assertSame([3, 7], $document['category_ids']);
        self::assertSame(['Electronics'], $document['categories.lvl0']);
        self::assertSame(['Electronics > Phones'], $document['categories.lvl1']);
    }

    public function test_build_includes_special_price_when_set(): void
    {
        $product = $this->createProductMock();

        $this->priceCalculator->method('getFinalPrice')->willReturn(79.99);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(59.99);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(false);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [],
            'category_ids' => [],
            'categories.lvl0' => [],
            'categories.lvl1' => [],
            'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturn(null);

        $document = $this->sut->build($product, 1);

        self::assertSame(59.99, $document['special_price']);
    }

    public function test_build_omits_special_price_when_null(): void
    {
        $product = $this->createProductMock();

        $this->priceCalculator->method('getFinalPrice')->willReturn(99.99);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [],
            'category_ids' => [],
            'categories.lvl0' => [],
            'categories.lvl1' => [],
            'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturn(null);

        $document = $this->sut->build($product, 1);

        self::assertArrayNotHasKey('special_price', $document);
    }

    public function test_build_merges_extra_attributes(): void
    {
        $product = $this->createProductMock();

        $this->priceCalculator->method('getFinalPrice')->willReturn(99.99);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [],
            'category_ids' => [],
            'categories.lvl0' => [],
            'categories.lvl1' => [],
            'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([
            'color' => 'Red',
            'size' => 'Large',
        ]);
        $product->method('getData')->willReturn(null);

        $document = $this->sut->build($product, 1);

        self::assertSame('Red', $document['color']);
        self::assertSame('Large', $document['size']);
    }

    public function test_build_converts_timestamps_to_unix_int(): void
    {
        $product = $this->createProductMock(
            createdAt: '2024-01-15 10:00:00',
            updatedAt: '2024-06-20 15:30:00',
        );

        $this->priceCalculator->method('getFinalPrice')->willReturn(0.0);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(false);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [],
            'category_ids' => [],
            'categories.lvl0' => [],
            'categories.lvl1' => [],
            'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturn(null);

        $document = $this->sut->build($product, 1);

        self::assertIsInt($document['created_at']);
        self::assertIsInt($document['updated_at']);
        self::assertGreaterThan(0, $document['created_at']);
        self::assertGreaterThan(0, $document['updated_at']);
    }

    public function test_build_strips_html_from_description(): void
    {
        $product = $this->createProductMock();

        $this->priceCalculator->method('getFinalPrice')->willReturn(99.99);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [],
            'category_ids' => [],
            'categories.lvl0' => [],
            'categories.lvl1' => [],
            'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturnMap([
            ['description', null, '<p>This is a <strong>great</strong> product.</p>'],
            ['short_description', null, null],
        ]);

        $document = $this->sut->build($product, 1);

        self::assertSame('This is a great product.', $document['description']);
    }

    public function test_build_strips_html_from_short_description(): void
    {
        $product = $this->createProductMock();

        $this->priceCalculator->method('getFinalPrice')->willReturn(99.99);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [],
            'category_ids' => [],
            'categories.lvl0' => [],
            'categories.lvl1' => [],
            'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturnMap([
            ['description', null, null],
            ['short_description', null, '<em>Quick</em> summary <br/> here.'],
        ]);

        $document = $this->sut->build($product, 1);

        self::assertSame('Quick summary  here.', $document['short_description']);
    }

    public function test_product_collection_filters_by_store_website(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(7);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->expects(self::once())->method('getStore')->with(3)->willReturn($store);
        $this->sut = $this->buildSut();

        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->expects(self::once())->method('addWebsiteFilter')->with(7);

        $this->sut->getProductCollection([], 3);
    }

    private function buildSut(): ProductDataBuilder
    {
        return new ProductDataBuilder(
            $this->attributeResolver,
            $this->priceCalculator,
            $this->imageResolver,
            $this->stockResolver,
            $this->categoryResolver,
            $this->urlResolver,
            $this->collectionFactory,
            $this->salesCountResolver,
            $this->reviewResolver,
            $this->storeManager,
            $this->config,
            $this->nameDecompounder,
        );
    }

    public function test_get_product_collection_sets_store_id(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::once())
            ->method('setStoreId')
            ->with(5);

        $collection->method('addAttributeToSelect');
        $collection->method('addUrlRewrite');
        $collection->method('addAttributeToFilter');

        $this->sut->getProductCollection([], 5);
    }

    public function test_get_product_collection_adds_all_attributes(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('setStoreId');
        $collection->expects(self::once())
            ->method('addAttributeToSelect')
            ->with('*');
        $collection->method('addUrlRewrite');
        $collection->method('addAttributeToFilter');

        $this->sut->getProductCollection([], 1);
    }

    public function test_get_product_collection_adds_url_rewrite(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('setStoreId');
        $collection->method('addAttributeToSelect');
        $collection->expects(self::once())
            ->method('addUrlRewrite');
        $collection->method('addAttributeToFilter');

        $this->sut->getProductCollection([], 1);
    }

    public function test_get_product_collection_filters_enabled_status(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('setStoreId');
        $collection->method('addAttributeToSelect');
        $collection->method('addUrlRewrite');
        $filters = [];
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function (string $attribute, mixed $condition) use (&$filters, $collection): ProductCollection {
                $filters[$attribute] = $condition;

                return $collection;
            },
        );

        $this->sut->getProductCollection([], 1);

        self::assertSame(Status::STATUS_ENABLED, $filters['status'] ?? null);
    }

    public function test_product_collection_includes_catalog_only_visibility(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('setStoreId');
        $collection->method('addAttributeToSelect');
        $collection->method('addUrlRewrite');
        $filters = [];
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function (string $attribute, mixed $condition) use (&$filters, $collection): ProductCollection {
                $filters[$attribute] = $condition;

                return $collection;
            },
        );

        $this->sut->getProductCollection([], 1);

        self::assertSame(
            ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]],
            $filters['visibility'] ?? null,
        );
    }

    public function test_product_collection_does_not_depend_on_category_product_index_for_visibility(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::never())->method('setVisibility');

        $this->sut->getProductCollection([], 1);
    }

    public function test_get_product_collection_filters_by_entity_ids_when_provided(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('setStoreId');
        $collection->method('addAttributeToSelect');
        $collection->method('addUrlRewrite');
        $collection->method('addAttributeToFilter');
        $collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with('entity_id', ['in' => [1, 2, 3]]);

        $this->sut->getProductCollection([1, 2, 3], 1);
    }

    public function test_get_product_collection_does_not_filter_by_entity_ids_when_empty(): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('setStoreId');
        $collection->method('addAttributeToSelect');
        $collection->method('addUrlRewrite');
        $collection->method('addAttributeToFilter');
        $collection->expects(self::never())
            ->method('addFieldToFilter');

        $this->sut->getProductCollection([], 1);
    }

    public function test_build_includes_sales_count_from_resolver(): void
    {
        $product = $this->createProductMock(id: 10);

        $this->priceCalculator->method('getFinalPrice')->willReturn(0.0);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [], 'category_ids' => [],
            'categories.lvl0' => [], 'categories.lvl1' => [], 'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturn(null);

        $salesCountResolver = $this->createMock(SalesCountResolverInterface::class);
        $salesCountResolver->method('getSalesCount')->willReturn(42);

        $reviewResolver = $this->createMock(ReviewResolverInterface::class);
        $reviewResolver->method('getRatingSummary')->willReturn(0);
        $reviewResolver->method('getReviewCount')->willReturn(0);

        $sut = new ProductDataBuilder(
            $this->attributeResolver,
            $this->priceCalculator,
            $this->imageResolver,
            $this->stockResolver,
            $this->categoryResolver,
            $this->urlResolver,
            $this->collectionFactory,
            $salesCountResolver,
            $reviewResolver,
            $this->storeManager,
            $this->config,
            $this->nameDecompounder,
        );

        $document = $sut->build($product, 1);

        self::assertSame(42, $document['sales_count']);
    }

    public function test_build_includes_rating_summary_from_resolver(): void
    {
        $product = $this->createProductMock(id: 10);

        $this->priceCalculator->method('getFinalPrice')->willReturn(0.0);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [], 'category_ids' => [],
            'categories.lvl0' => [], 'categories.lvl1' => [], 'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturn(null);

        $salesCountResolver = $this->createMock(SalesCountResolverInterface::class);
        $salesCountResolver->method('getSalesCount')->willReturn(0);

        $reviewResolver = $this->createMock(ReviewResolverInterface::class);
        $reviewResolver->method('getRatingSummary')->willReturn(80);
        $reviewResolver->method('getReviewCount')->willReturn(0);

        $sut = new ProductDataBuilder(
            $this->attributeResolver,
            $this->priceCalculator,
            $this->imageResolver,
            $this->stockResolver,
            $this->categoryResolver,
            $this->urlResolver,
            $this->collectionFactory,
            $salesCountResolver,
            $reviewResolver,
            $this->storeManager,
            $this->config,
            $this->nameDecompounder,
        );

        $document = $sut->build($product, 2);

        self::assertSame(80, $document['rating_summary']);
    }

    public function test_build_includes_review_count_from_resolver(): void
    {
        $product = $this->createProductMock(id: 10);

        $this->priceCalculator->method('getFinalPrice')->willReturn(0.0);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [], 'category_ids' => [],
            'categories.lvl0' => [], 'categories.lvl1' => [], 'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturn(null);

        $salesCountResolver = $this->createMock(SalesCountResolverInterface::class);
        $salesCountResolver->method('getSalesCount')->willReturn(0);

        $reviewResolver = $this->createMock(ReviewResolverInterface::class);
        $reviewResolver->method('getRatingSummary')->willReturn(0);
        $reviewResolver->method('getReviewCount')->willReturn(15);

        $sut = new ProductDataBuilder(
            $this->attributeResolver,
            $this->priceCalculator,
            $this->imageResolver,
            $this->stockResolver,
            $this->categoryResolver,
            $this->urlResolver,
            $this->collectionFactory,
            $salesCountResolver,
            $reviewResolver,
            $this->storeManager,
            $this->config,
            $this->nameDecompounder,
        );

        $document = $sut->build($product, 2);

        self::assertSame(15, $document['review_count']);
    }

    public function test_builder_omits_field_and_never_loads_splitter_when_disabled(): void
    {
        $product = $this->createProductMock(name: 'Treppensackkarre bis 200 kg');
        $this->stubEmptyProductData($product);
        $this->config->method('isGermanDecompoundingEnabled')->with(3)->willReturn(false);
        $this->splitterProvider->expects(self::never())->method('get');
        $this->catalogWordsProvider->expects(self::never())->method('getWords');

        $document = $this->sut->build($product, 3);

        self::assertArrayNotHasKey('name_decompounded', $document);
    }

    public function test_builder_sets_decompounded_name_when_enabled(): void
    {
        $product = $this->createProductMock(name: 'Treppensackkarre bis 200 kg');
        $this->stubEmptyProductData($product);
        $this->config->method('isGermanDecompoundingEnabled')->with(3)->willReturn(true);
        $this->config->method('getDecompoundSkipAttribute')->with(3)->willReturn('');
        $this->nameDecompounder = $this->createMock(NameDecompounder::class);
        $this->nameDecompounder->expects(self::once())->method('decompound')
            ->with('Treppensackkarre bis 200 kg', '', 3)
            ->willReturn('Treppensackkarre Sackkarre Karre bis 200 kg');

        $document = $this->buildSut()->build($product, 3);

        self::assertSame('Treppensackkarre Sackkarre Karre bis 200 kg', $document['name_decompounded']);
    }

    /** @param string|string[]|false $optionText */
    #[DataProvider('skipAttributeProvider')]
    public function test_builder_passes_skip_attribute_value_of_the_product(
        string $frontendInput,
        string|array|false $optionText,
        ?string $rawValue,
        string $expected,
    ): void {
        $product = $this->createProductMock(name: 'Schuhregal Blackpool');
        $this->stubEmptyProductData($product, ['product_line' => $rawValue]);
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getFrontendInput')->willReturn($frontendInput);
        $resource = $this->createMock(ProductResource::class);
        $resource->method('getAttribute')->with('product_line')->willReturn($attribute);
        $product->method('getResource')->willReturn($resource);
        $product->method('getAttributeText')->with('product_line')->willReturn($optionText);
        $this->config->method('isGermanDecompoundingEnabled')->willReturn(true);
        $this->config->method('getDecompoundSkipAttribute')->willReturn('product_line');
        $this->nameDecompounder = $this->createMock(NameDecompounder::class);
        $this->nameDecompounder->expects(self::once())->method('decompound')
            ->with('Schuhregal Blackpool', $expected, 3)
            ->willReturn('Schuhregal Regal Blackpool');

        $this->buildSut()->build($product, 3);
    }

    /** @return array<string, array{string, string|string[]|false, ?string, string}> */
    public static function skipAttributeProvider(): array
    {
        return [
            'select uses the option label' => ['select', 'Blackpool', '42', 'Blackpool'],
            'multiselect joins the option labels' => [
                'multiselect',
                ['Blackpool', 'Lausanne'],
                '42,43',
                'Blackpool Lausanne',
            ],
            'select without a value' => ['select', false, null, ''],
            'text uses the raw value' => ['text', false, 'Blackpool', 'Blackpool'],
            'text without a value' => ['text', false, null, ''],
        ];
    }

    public function test_builder_passes_empty_skip_words_when_attribute_does_not_exist(): void
    {
        $product = $this->createProductMock(name: 'Schuhregal');
        $this->stubEmptyProductData($product);
        $resource = $this->createMock(ProductResource::class);
        $resource->method('getAttribute')->willReturn(false);
        $product->method('getResource')->willReturn($resource);
        $this->config->method('isGermanDecompoundingEnabled')->willReturn(true);
        $this->config->method('getDecompoundSkipAttribute')->willReturn('removed_attribute');
        $this->nameDecompounder = $this->createMock(NameDecompounder::class);
        $this->nameDecompounder->expects(self::once())->method('decompound')
            ->with('Schuhregal', '', 3)
            ->willReturn('Schuhregal Regal');

        $this->buildSut()->build($product, 3);
    }

    /** @param array<string, mixed> $data */
    private function stubEmptyProductData(Product&MockObject $product, array $data = []): void
    {
        $this->priceCalculator->method('getFinalPrice')->willReturn(0.0);
        $this->priceCalculator->method('getSpecialPrice')->willReturn(null);
        $this->imageResolver->method('getImageUrl')->willReturn('');
        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->urlResolver->method('getProductUrl')->willReturn('');
        $this->categoryResolver->method('getCategoryData')->willReturn([
            'categories' => [], 'category_ids' => [],
            'categories.lvl0' => [], 'categories.lvl1' => [], 'categories.lvl2' => [],
        ]);
        $this->attributeResolver->method('getExtraAttributes')->willReturn([]);
        $product->method('getData')->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null);
    }

    /**
     * @return Product&MockObject
     */
    private function createProductMock(
        int $id = 1,
        string $sku = 'SKU-001',
        string $name = 'Product Name',
        string $typeId = 'simple',
        int $visibility = 4,
        string $createdAt = '2024-01-01 00:00:00',
        string $updatedAt = '2024-01-01 00:00:00',
    ): Product&MockObject {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn($name);
        $product->method('getTypeId')->willReturn($typeId);
        $product->method('getVisibility')->willReturn($visibility);
        $product->method('getCreatedAt')->willReturn($createdAt);
        $product->method('getUpdatedAt')->willReturn($updatedAt);

        return $product;
    }
}
