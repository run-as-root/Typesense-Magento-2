<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Frontend\AutocompleteConfigViewModel;

final class AutocompleteConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private StoreInterface&MockObject $store;
    private AutocompleteConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config                = $this->createMock(TypeSenseConfigInterface::class);
        $this->storeManager          = $this->createMock(StoreManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getCode')->willReturn('default');
        $this->store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->sut = new AutocompleteConfigViewModel(
            $this->config,
            $this->storeManager,
            $this->collectionNameResolver,
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

    public function test_get_config_returns_array_with_expected_keys(): void
    {
        $this->config->method('getHost')->willReturn('search.example.com');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('https');
        $this->config->method('getSearchOnlyApiKey')->willReturn('xyz-search-key');
        $this->config->method('getAutocompleteProductCount')->willReturn(6);

        $this->collectionNameResolver
            ->method('resolve')
            ->willReturnMap([
                ['product', 'default', 1, 'prefix_product_default'],
                ['category', 'default', 1, 'prefix_category_default'],
                ['suggestion', 'default', 1, 'prefix_suggestion_default'],
            ]);

        $result = $this->sut->getConfig();

        self::assertArrayHasKey('typesenseHost', $result);
        self::assertArrayHasKey('typesensePort', $result);
        self::assertArrayHasKey('typesenseProtocol', $result);
        self::assertArrayHasKey('typesenseSearchOnlyApiKey', $result);
        self::assertArrayHasKey('productCollection', $result);
        self::assertArrayHasKey('categoryCollection', $result);
        self::assertArrayHasKey('suggestionCollection', $result);
        self::assertArrayHasKey('productCount', $result);

        self::assertSame('search.example.com', $result['typesenseHost']);
        self::assertSame(8108, $result['typesensePort']);
        self::assertSame('https', $result['typesenseProtocol']);
        self::assertSame('xyz-search-key', $result['typesenseSearchOnlyApiKey']);
        self::assertSame('prefix_product_default', $result['productCollection']);
        self::assertSame('prefix_category_default', $result['categoryCollection']);
        self::assertSame('prefix_suggestion_default', $result['suggestionCollection']);
        self::assertSame(6, $result['productCount']);
    }

    public function test_get_json_config_returns_valid_json_string(): void
    {
        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('key');
        $this->config->method('getAutocompleteProductCount')->willReturn(4);

        $this->collectionNameResolver->method('resolve')->willReturn('collection_name');

        $json = $this->sut->getJsonConfig();

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('typesenseHost', $decoded);
    }
}
