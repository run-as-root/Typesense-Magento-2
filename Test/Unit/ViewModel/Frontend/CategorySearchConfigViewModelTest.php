<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Frontend\CategorySearchConfigViewModel;

final class CategorySearchConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private Registry&MockObject $registry;
    private CategorySearchConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->registry = $this->createMock(Registry::class);

        $this->sut = new CategorySearchConfigViewModel(
            $this->config,
            $this->storeManager,
            $this->collectionNameResolver,
            $this->registry,
        );
    }

    public function test_is_enabled_returns_true_when_both_flags_are_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_main_config_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_replace_category_page_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(false);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_get_current_category_id_returns_id_from_registry(): void
    {
        $category = $this->createMock(\Magento\Catalog\Model\Category::class);
        $category->method('getId')->willReturn('42');

        $this->registry->method('registry')
            ->with('current_category')
            ->willReturn($category);

        self::assertSame(42, $this->sut->getCurrentCategoryId());
    }

    public function test_get_current_category_id_returns_null_when_no_category_in_registry(): void
    {
        $this->registry->method('registry')
            ->with('current_category')
            ->willReturn(null);

        self::assertNull($this->sut->getCurrentCategoryId());
    }

    public function test_get_config_includes_category_id_key(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->method('getSearchHost')->willReturn('localhost');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('xyz');
        $this->config->method('getProductsPerPage')->willReturn(24);
        $this->config->method('getEnabledSortOptions')->willReturn([]);
        $this->config->method('getTileAttributes')->willReturn([]);

        $this->collectionNameResolver->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('rar_products_default');

        $category = $this->createMock(\Magento\Catalog\Model\Category::class);
        $category->method('getId')->willReturn('7');
        $this->registry->method('registry')
            ->with('current_category')
            ->willReturn($category);

        $result = $this->sut->getConfig();

        self::assertArrayHasKey('categoryId', $result);
        self::assertSame(7, $result['categoryId']);
    }

    public function test_get_json_config_returns_valid_json_string(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->method('getSearchHost')->willReturn('localhost');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('xyz');
        $this->config->method('getProductsPerPage')->willReturn(24);
        $this->config->method('getEnabledSortOptions')->willReturn([]);
        $this->config->method('getTileAttributes')->willReturn([]);

        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_default');
        $this->registry->method('registry')->willReturn(null);

        $json = $this->sut->getJsonConfig();

        self::assertJson($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('categoryId', $decoded);
    }
}
