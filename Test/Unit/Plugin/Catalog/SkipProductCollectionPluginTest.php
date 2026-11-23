<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Plugin\Catalog;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Plugin\Catalog\SkipProductCollectionPlugin;

final class SkipProductCollectionPluginTest extends TestCase
{
    private ProcessorInterface&MockObject $layoutUpdate;
    private TypeSenseConfigInterface&MockObject $config;
    private CollectionFactory&MockObject $collectionFactory;
    private ListProduct&MockObject $block;
    private Collection&MockObject $layerCollection;
    private SkipProductCollectionPlugin $sut;

    protected function setUp(): void
    {
        $this->layoutUpdate = $this->createMock(ProcessorInterface::class);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getUpdate')->willReturn($this->layoutUpdate);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->block = $this->createMock(ListProduct::class);
        $this->layerCollection = $this->createMock(Collection::class);

        $this->sut = new SkipProductCollectionPlugin($layout, $this->config, $this->collectionFactory);
    }

    public function test_typesense_category_page_gets_empty_product_collection(): void
    {
        $this->layoutUpdate->method('getHandles')
            ->willReturn(['default', 'catalog_category_view', 'typesense_category_search']);
        $empty = $this->expectEmptyCollection();
        $this->block->expects(self::once())->method('setCollection')->with($empty);

        self::assertSame($empty, $this->sut->afterGetLoadedProductCollection($this->block, $this->layerCollection));
    }

    public function test_regular_category_page_keeps_product_collection(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['default', 'catalog_category_view']);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);
        $this->collectionFactory->expects(self::never())->method('create');
        $this->block->expects(self::never())->method('setCollection');

        self::assertSame(
            $this->layerCollection,
            $this->sut->afterGetLoadedProductCollection($this->block, $this->layerCollection),
        );
    }

    public function test_instant_search_page_gets_empty_product_collection(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['default', 'catalogsearch_result_index']);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);
        $empty = $this->expectEmptyCollection();

        self::assertSame($empty, $this->sut->afterGetLoadedProductCollection($this->block, $this->layerCollection));
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

        self::assertSame(
            $this->layerCollection,
            $this->sut->afterGetLoadedProductCollection($this->block, $this->layerCollection),
        );
    }

    /** @return array<string, array{bool, bool}> */
    public static function disabledInstantSearchProvider(): array
    {
        return [
            'module disabled' => [false, true],
            'instant search disabled' => [true, false],
        ];
    }

    public function test_already_emptied_collection_is_not_replaced_again(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['typesense_category_search']);
        $this->layerCollection->method('getFlag')
            ->with(SkipProductCollectionPlugin::EMPTY_COLLECTION_FLAG)
            ->willReturn(true);
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertSame(
            $this->layerCollection,
            $this->sut->afterGetLoadedProductCollection($this->block, $this->layerCollection),
        );
    }

    private function expectEmptyCollection(): Collection&MockObject
    {
        $empty = $this->createMock(Collection::class);
        $empty->expects(self::once())->method('setPageSize')->with(0)->willReturnSelf();
        $empty->expects(self::once())->method('addFieldToFilter')->with('entity_id', ['eq' => 0])->willReturnSelf();
        $empty->expects(self::once())->method('setFlag')
            ->with(SkipProductCollectionPlugin::EMPTY_COLLECTION_FLAG, true)
            ->willReturnSelf();
        $this->collectionFactory->expects(self::once())->method('create')->willReturn($empty);

        return $empty;
    }
}
