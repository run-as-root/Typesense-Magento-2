<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Conversation\ConversationModelManager;
use RunAsRoot\TypeSense\ViewModel\Frontend\InstantSearchConfigViewModel;

final class InstantSearchConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private ConversationModelManager&MockObject $conversationModelManager;
    private StoreInterface&MockObject $store;
    private InstantSearchConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config                    = $this->createMock(TypeSenseConfigInterface::class);
        $this->storeManager              = $this->createMock(StoreManagerInterface::class);
        $this->collectionNameResolver    = $this->createMock(CollectionNameResolverInterface::class);
        $this->conversationModelManager  = $this->createMock(ConversationModelManager::class);
        $this->conversationModelManager->method('getModelId')->willReturn('rar-product-assistant');

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getCode')->willReturn('default');
        $this->store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->sut = new InstantSearchConfigViewModel(
            $this->config,
            $this->storeManager,
            $this->collectionNameResolver,
            $this->conversationModelManager,
        );
    }

    public function test_is_enabled_returns_true_when_both_module_and_instant_search_are_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_instant_search_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(false);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_module_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_get_config_returns_array_with_expected_keys(): void
    {
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchProtocol')->willReturn('https');
        $this->config->method('getSearchOnlyApiKey')->willReturn('xyz-search-key');
        $this->config->method('getProductsPerPage')->willReturn(24);
        $this->config->method('getEnabledSortOptions')->willReturn([]);
        $this->config->method('getTileAttributes')->willReturn([]);

        $this->collectionNameResolver
            ->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('prefix_product_default');

        $result = $this->sut->getConfig();

        self::assertArrayHasKey('typesenseHost', $result);
        self::assertArrayHasKey('typesensePort', $result);
        self::assertArrayHasKey('typesenseProtocol', $result);
        self::assertArrayHasKey('typesenseSearchOnlyApiKey', $result);
        self::assertArrayHasKey('productCollection', $result);
        self::assertArrayHasKey('productsPerPage', $result);
        self::assertArrayHasKey('facetAttributes', $result);
        self::assertArrayHasKey('sortOptions', $result);
        self::assertArrayHasKey('tileAttributes', $result);

        self::assertSame('search.example.com', $result['typesenseHost']);
        self::assertSame(8108, $result['typesensePort']);
        self::assertSame('https', $result['typesenseProtocol']);
        self::assertSame('xyz-search-key', $result['typesenseSearchOnlyApiKey']);
        self::assertSame('prefix_product_default', $result['productCollection']);
        self::assertSame(24, $result['productsPerPage']);
    }

    public function test_get_sort_options_returns_array_from_config(): void
    {
        $sortOptions = [
            ['label' => 'Relevance', 'value' => ''],
            ['label' => 'Price: Low to High', 'value' => 'price:asc'],
        ];
        $this->config->method('getEnabledSortOptions')->willReturn($sortOptions);

        $result = $this->sut->getSortOptions();

        self::assertSame($sortOptions, $result);
    }

    public function test_get_facet_attributes_returns_configured_filters(): void
    {
        $this->config->method('getFacetFilters')->willReturn(['color', 'size']);

        $result = $this->sut->getFacetAttributes();

        self::assertSame(['color', 'size'], $result);
    }

    public function test_get_config_includes_conversational_search_when_enabled(): void
    {
        $this->config->method('isConversationalSearchEnabled')->willReturn(true);
        $this->config->method('getSearchHost')->willReturn('localhost');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchProtocol')->willReturn('https');
        $this->config->method('getSearchOnlyApiKey')->willReturn('key');
        $this->config->method('getProductsPerPage')->willReturn(24);
        $this->config->method('getEnabledSortOptions')->willReturn([]);
        $this->config->method('getTileAttributes')->willReturn([]);
        $this->config->method('getFacetFilters')->willReturn([]);

        $this->collectionNameResolver->method('resolve')->willReturn('test_collection');

        $result = $this->sut->getConfig();

        self::assertArrayHasKey('conversationalSearch', $result);
        self::assertTrue($result['conversationalSearch']['enabled']);
        self::assertSame('rar-product-assistant', $result['conversationalSearch']['modelId']);
    }
}
