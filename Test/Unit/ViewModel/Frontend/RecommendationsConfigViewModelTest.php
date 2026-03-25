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

    public function test_get_config_returns_expected_keys_and_values(): void
    {
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(443);
        $this->config->method('getSearchProtocol')->willReturn('https');
        $this->config->method('getSearchOnlyApiKey')->willReturn('xyz-key');
        $this->config->method('getRecommendationsLimit')->willReturn(8);

        $this->collectionNameResolver
            ->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('rar_product_default');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(42);

        $result = $this->sut->getConfig($product);

        self::assertSame('search.example.com', $result['typesenseHost']);
        self::assertSame(443, $result['typesensePort']);
        self::assertSame('https', $result['typesenseProtocol']);
        self::assertSame('xyz-key', $result['typesenseSearchOnlyApiKey']);
        self::assertSame('rar_product_default', $result['productCollection']);
        self::assertSame('42', $result['productId']);
        self::assertSame(8, $result['limit']);
    }

    public function test_get_json_config_returns_valid_json(): void
    {
        $this->config->method('getSearchHost')->willReturn('localhost');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('key');
        $this->config->method('getRecommendationsLimit')->willReturn(6);

        $this->collectionNameResolver->method('resolve')->willReturn('test_collection');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);

        $json = $this->sut->getJsonConfig($product);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertSame('10', $decoded['productId']);
        self::assertSame(6, $decoded['limit']);
    }
}
