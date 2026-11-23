<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Plugin\Catalog;

use ArrayIterator;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Plugin\Catalog\SkipProductCollectionPlugin;

/**
 * The block is a real ListProduct without its constructor: building the layer collection would dereference the
 * missing registry/layer and throw, so a passing read proves the empty collection was in place first.
 */
final class SkipProductCollectionPluginTest extends TestCase
{
    private ProcessorInterface&MockObject $layoutUpdate;
    private TypeSenseConfigInterface&MockObject $config;
    private CollectionFactory&MockObject $collectionFactory;
    private ListProduct&MockObject $block;
    private SkipProductCollectionPlugin $sut;

    protected function setUp(): void
    {
        $this->layoutUpdate = $this->createMock(ProcessorInterface::class);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getUpdate')->willReturn($this->layoutUpdate);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->block = $this->getMockBuilder(ListProduct::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLayer'])
            ->getMock();

        $this->sut = new SkipProductCollectionPlugin($layout, $this->config, $this->collectionFactory);
    }

    public function test_typesense_category_page_gets_empty_product_collection(): void
    {
        $this->layoutUpdate->method('getHandles')
            ->willReturn(['default', 'catalog_category_view', 'typesense_category_search']);
        $empty = $this->expectEmptyCollection();

        $this->sut->beforeGetLoadedProductCollection($this->block);

        self::assertSame($empty, $this->block->getLoadedProductCollection());
    }

    public function test_regular_category_page_keeps_product_collection(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['default', 'catalog_category_view']);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);
        $this->collectionFactory->expects(self::never())->method('create');

        $this->sut->beforeGetLoadedProductCollection($this->block);
        $this->sut->beforeToHtml($this->block);
        $this->sut->beforeGetIdentities($this->block);

        self::assertNull($this->assignedCollection());
    }

    public function test_empty_collection_is_in_place_before_before_to_html_reads_it(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['typesense_category_search']);
        $empty = $this->expectEmptyCollection();

        $this->sut->beforeToHtml($this->block);

        // _beforeToHtml() starts with this protected call and loads whatever it returns.
        self::assertSame($empty, (new ReflectionMethod(ListProduct::class, '_getProductCollection'))->invoke($this->block));
    }

    public function test_empty_collection_is_in_place_before_get_identities_iterates_it(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['typesense_category_search']);
        $empty = $this->expectEmptyCollection();
        $empty->method('getIterator')->willReturn(new ArrayIterator([]));
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(7);
        $category->method('getData')->with('display_mode')->willReturn(Category::DM_PRODUCT);
        $layer = $this->createMock(Layer::class);
        $layer->method('getCurrentCategory')->willReturn($category);
        $this->block->method('getLayer')->willReturn($layer);

        $this->sut->beforeGetIdentities($this->block);

        self::assertSame([Product::CACHE_PRODUCT_CATEGORY_TAG . '_7'], $this->block->getIdentities());
    }

    public function test_instant_search_page_gets_empty_product_collection(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['default', 'catalogsearch_result_index']);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);
        $empty = $this->expectEmptyCollection();

        $this->sut->beforeGetLoadedProductCollection($this->block);

        self::assertSame($empty, $this->block->getLoadedProductCollection());
    }

    #[DataProvider('disabledInstantSearchProvider')]
    public function test_search_page_keeps_product_collection_without_instant_search(
        bool $moduleEnabled,
        bool $instantSearchEnabled,
    ): void {
        $this->layoutUpdate->method('getHandles')->willReturn(['default', 'catalogsearch_result_index']);
        $this->config->method('isEnabled')->willReturn($moduleEnabled);
        $this->config->method('isInstantSearchEnabled')->willReturn($instantSearchEnabled);
        $this->collectionFactory->expects(self::never())->method('create');

        $this->sut->beforeGetLoadedProductCollection($this->block);

        self::assertNull($this->assignedCollection());
    }

    /** @return array<string, array{bool, bool}> */
    public static function disabledInstantSearchProvider(): array
    {
        return [
            'module disabled' => [false, true],
            'instant search disabled' => [true, false],
        ];
    }

    public function test_each_block_gets_one_empty_collection_across_hooks(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['typesense_category_search']);
        $empty = $this->expectEmptyCollection();

        $this->sut->beforeToHtml($this->block);
        $this->sut->beforeGetLoadedProductCollection($this->block);
        $this->sut->beforeGetIdentities($this->block);

        self::assertSame($empty, $this->assignedCollection());
    }

    private function expectEmptyCollection(): Collection&MockObject
    {
        $empty = $this->createMock(Collection::class);
        $empty->expects(self::never())->method('setPageSize');
        $empty->expects(self::once())->method('addFieldToFilter')->with('entity_id', ['eq' => 0])->willReturnSelf();
        $this->collectionFactory->expects(self::once())->method('create')->willReturn($empty);

        return $empty;
    }

    private function assignedCollection(): mixed
    {
        return (new ReflectionProperty(ListProduct::class, '_productCollection'))->getValue($this->block);
    }
}
