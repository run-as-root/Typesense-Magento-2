<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\LandingPageInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Frontend\LandingPageViewModel;

final class LandingPageViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private Registry&MockObject $registry;
    private LandingPageViewModel $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->registry = $this->createMock(Registry::class);

        $this->sut = new LandingPageViewModel(
            $this->config,
            $this->storeManager,
            $this->collectionNameResolver,
            $this->registry,
        );
    }

    public function test_get_landing_page_returns_entity_from_registry(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);

        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn($landingPage);

        self::assertSame($landingPage, $this->sut->getLandingPage());
    }

    public function test_get_landing_page_returns_null_when_not_in_registry(): void
    {
        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn(null);

        self::assertNull($this->sut->getLandingPage());
    }

    public function test_get_search_config_includes_landing_page_query_and_filter_by(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('getQuery')->willReturn('running shoes');
        $landingPage->method('getFilterBy')->willReturn('brand:Nike');
        $landingPage->method('getSortBy')->willReturn('price:asc');

        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn($landingPage);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('search-key');
        $this->config->method('getProductsPerPage')->willReturn(24);

        $this->collectionNameResolver->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('rar_products_default');

        $result = $this->sut->getSearchConfig();

        self::assertSame('running shoes', $result['query']);
        self::assertSame('brand:Nike', $result['filterBy']);
        self::assertSame('price:asc', $result['sortBy']);
    }

    public function test_get_search_config_uses_wildcard_query_when_landing_page_is_null(): void
    {
        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn(null);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('search-key');
        $this->config->method('getProductsPerPage')->willReturn(24);

        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_default');

        $result = $this->sut->getSearchConfig();

        self::assertSame('*', $result['query']);
        self::assertSame('', $result['filterBy']);
        self::assertSame('', $result['sortBy']);
    }

    public function test_get_cms_content_returns_content_from_landing_page(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('getCmsContent')->willReturn('<p>Sale items this week!</p>');

        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn($landingPage);

        self::assertSame('<p>Sale items this week!</p>', $this->sut->getCmsContent());
    }

    public function test_get_cms_content_returns_empty_string_when_no_landing_page(): void
    {
        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn(null);

        self::assertSame('', $this->sut->getCmsContent());
    }

    public function test_get_cms_content_returns_empty_string_when_cms_content_is_null(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('getCmsContent')->willReturn(null);

        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn($landingPage);

        self::assertSame('', $this->sut->getCmsContent());
    }

    public function test_has_banner_returns_true_when_banner_config_is_set(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('getBannerConfig')->willReturn(['image' => 'banner.jpg', 'title' => 'Sale']);

        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn($landingPage);

        self::assertTrue($this->sut->hasBanner());
    }

    public function test_has_banner_returns_false_when_banner_config_is_empty(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('getBannerConfig')->willReturn([]);

        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn($landingPage);

        self::assertFalse($this->sut->hasBanner());
    }

    public function test_get_json_search_config_returns_valid_json(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('getQuery')->willReturn('*');
        $landingPage->method('getFilterBy')->willReturn('');
        $landingPage->method('getSortBy')->willReturn('');

        $this->registry->method('registry')
            ->with('current_landing_page')
            ->willReturn($landingPage);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('search-key');
        $this->config->method('getProductsPerPage')->willReturn(24);

        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_default');

        $json = $this->sut->getJsonSearchConfig();

        self::assertJson($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('query', $decoded);
        self::assertArrayHasKey('filterBy', $decoded);
        self::assertArrayHasKey('facetAttributes', $decoded);
    }
}
