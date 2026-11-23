<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface as MsiStockResolverInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\Resolver\SalableResolver;

final class SalableResolverTest extends TestCase
{
    private IsProductSalableInterface&MockObject $isProductSalable;
    private MsiStockResolverInterface&MockObject $msiStockResolver;
    private GetStockItemConfigurationInterface&MockObject $getStockItemConfiguration;
    private StoreManagerInterface&MockObject $storeManager;
    private SalableResolver $sut;

    protected function setUp(): void
    {
        $this->isProductSalable = $this->createMock(IsProductSalableInterface::class);
        $this->msiStockResolver = $this->createMock(MsiStockResolverInterface::class);
        $this->getStockItemConfiguration = $this->createMock(GetStockItemConfigurationInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(4);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('loewen');
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->with(7)->willReturn($store);
        $this->storeManager->method('getWebsite')->with(4)->willReturn($website);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(2);
        $this->msiStockResolver->method('execute')
            ->with(SalesChannelInterface::TYPE_WEBSITE, 'loewen')
            ->willReturn($stock);

        $this->sut = new SalableResolver(
            $this->isProductSalable,
            $this->msiStockResolver,
            $this->getStockItemConfiguration,
            $this->storeManager,
        );
    }

    public function test_is_salable_checks_the_sku_against_the_stock_of_the_store_website(): void
    {
        $this->isProductSalable->expects(self::once())->method('execute')->with('GIN-070', 2)->willReturn(true);

        self::assertTrue($this->sut->isSalable($this->createProduct('GIN-070'), 7));
    }

    public function test_stock_is_resolved_once_per_store(): void
    {
        $this->msiStockResolver->expects(self::once())->method('execute');
        $this->isProductSalable->method('execute')->willReturn(false);

        $this->sut->isSalable($this->createProduct('A'), 7);
        $this->sut->isSalable($this->createProduct('B'), 7);
    }

    public function test_qty_increment_is_the_configured_increment_of_the_stock_item(): void
    {
        $this->getStockItemConfiguration->method('execute')->with('GIN-035', 2)
            ->willReturn($this->createConfiguration(true, 6.0));

        self::assertSame(6.0, $this->sut->getQtyIncrement($this->createProduct('GIN-035'), 7));
    }

    public function test_qty_increment_is_one_when_increments_are_disabled(): void
    {
        $this->getStockItemConfiguration->method('execute')->willReturn($this->createConfiguration(false, 0.0));

        self::assertSame(1.0, $this->sut->getQtyIncrement($this->createProduct('GIN-035'), 7));
    }

    public function test_qty_increment_is_one_when_the_sku_has_no_stock_configuration(): void
    {
        $this->getStockItemConfiguration->method('execute')
            ->willThrowException(new LocalizedException(__('SKU is not assigned to stock')));

        self::assertSame(1.0, $this->sut->getQtyIncrement($this->createProduct('GIN-035'), 7));
    }

    private function createProduct(string $sku): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }

    private function createConfiguration(bool $enabled, float $increment): StockItemConfigurationInterface&MockObject
    {
        $configuration = $this->createMock(StockItemConfigurationInterface::class);
        $configuration->method('isEnableQtyIncrements')->willReturn($enabled);
        $configuration->method('getQtyIncrements')->willReturn($increment);

        return $configuration;
    }
}
