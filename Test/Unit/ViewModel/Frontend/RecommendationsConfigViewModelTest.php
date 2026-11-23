<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Frontend\RecommendationsConfigViewModel;

final class RecommendationsConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private StoreInterface&MockObject $store;
    private RecommendationsConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config                 = $this->createMock(TypeSenseConfigInterface::class);
        $this->storeManager           = $this->createMock(StoreManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getCode')->willReturn('default');
        $this->store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->sut = new RecommendationsConfigViewModel(
            $this->config,
            $this->storeManager,
            $this->collectionNameResolver,
        );
    }

    public function test_is_enabled_delegates_to_config(): void
    {
        $this->config->method('isRecommendationsEnabled')->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_config_disabled(): void
    {
        $this->config->method('isRecommendationsEnabled')->willReturn(false);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_get_config_holds_only_what_the_shared_client_does_not_know(): void
    {
        $this->config->method('getRecommendationsLimit')->willReturn(8);
        $this->collectionNameResolver
            ->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('rar_product_default');
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(42);

        self::assertSame(
            ['productCollection' => 'rar_product_default', 'productId' => '42', 'limit' => 8, 'showPrices' => true],
            $this->sut->getConfig($product),
        );
    }

    public function test_show_prices_follows_can_show_prices(): void
    {
        $sut = $this->getMockBuilder(RecommendationsConfigViewModel::class)
            ->setConstructorArgs([$this->config, $this->storeManager, $this->collectionNameResolver])
            ->onlyMethods(['canShowPrices'])
            ->getMock();
        $sut->method('canShowPrices')->willReturn(false);

        self::assertFalse($sut->getConfig($this->createMock(ProductInterface::class))['showPrices']);
    }

    public function test_get_json_config_escapes_markup(): void
    {
        $this->config->method('getRecommendationsLimit')->willReturn(6);
        $this->collectionNameResolver->method('resolve')->willReturn('</script><b>');
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);

        $json = $this->sut->getJsonConfig($product);

        self::assertStringNotContainsString('<', $json);
        self::assertSame('</script><b>', json_decode($json, true)['productCollection']);
        self::assertSame('10', json_decode($json, true)['productId']);
    }
}
