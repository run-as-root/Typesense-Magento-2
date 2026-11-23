<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Observer\LayoutLoadBefore;

final class LayoutLoadBeforeTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private Registry&MockObject $registry;
    private LayoutLoadBefore $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->registry = $this->createMock(Registry::class);
        $this->sut = new LayoutLoadBefore($this->config, $this->registry);
    }

    public function test_adds_layout_handle_when_on_category_page_and_config_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);

        $this->sut->execute($this->createObserver('catalog_category_view', ['typesense_category_search']));
    }

    public function test_does_not_add_handle_when_not_on_a_listing_page(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        $this->sut->execute($this->createObserver('cms_index_index', []));
    }

    public function test_does_not_add_handle_when_config_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        $observer = $this->createMock(Observer::class);
        $observer->expects(self::never())->method('getData');

        $this->sut->execute($observer);
    }

    public function test_does_not_add_handle_when_replace_category_page_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(false);

        $this->sut->execute($this->createObserver('catalog_category_view', []));
    }

    public function test_category_in_page_display_mode_keeps_magento_listing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);
        $category = $this->createMock(Category::class);
        $category->method('getDisplayMode')->willReturn(Category::DM_PAGE);
        $this->registry->method('registry')->with('current_category')->willReturn($category);

        $this->sut->execute($this->createObserver('catalog_category_view', []));
    }

    public function test_search_page_gets_typesense_handle_when_instant_search_is_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        $this->sut->execute($this->createObserver('catalogsearch_result_index', ['typesense_search_result']));
    }

    public function test_search_page_keeps_magento_results_when_instant_search_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(false);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);

        $this->sut->execute($this->createObserver('catalogsearch_result_index', []));
    }

    /** @return array<string, array{bool, bool, string, string[]}> */
    public static function autocompleteProvider(): array
    {
        return [
            'enabled, any page' => [true, true, 'cms_index_index', ['typesense_autocomplete']],
            'enabled, with listing handle' => [
                true,
                true,
                'catalogsearch_result_index',
                ['typesense_search_result', 'typesense_autocomplete'],
            ],
            'autocomplete disabled' => [true, false, 'cms_index_index', []],
            'module disabled' => [false, true, 'cms_index_index', []],
        ];
    }

    /** @param string[] $expectedHandles */
    #[DataProvider('autocompleteProvider')]
    public function test_autocomplete_handle_added_only_when_enabled(
        bool $moduleEnabled,
        bool $autocompleteEnabled,
        string $fullActionName,
        array $expectedHandles,
    ): void {
        $this->config->method('isEnabled')->willReturn($moduleEnabled);
        $this->config->method('isAutocompleteEnabled')->willReturn($autocompleteEnabled);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        $this->sut->execute($this->createObserver($fullActionName, $expectedHandles));
    }

    /** @param string[] $expectedHandles */
    private function createObserver(string $fullActionName, array $expectedHandles): Observer&MockObject
    {
        $layoutUpdate = $this->getMockBuilder(\stdClass::class)->addMethods(['addHandle'])->getMock();
        $layoutUpdate->expects(self::exactly(count($expectedHandles)))
            ->method('addHandle')
            ->willReturnCallback(static function (string $handle) use ($expectedHandles): void {
                self::assertContains($handle, $expectedHandles);
            });
        $layout = $this->getMockBuilder(\stdClass::class)->addMethods(['getUpdate'])->getMock();
        $layout->method('getUpdate')->willReturn($layoutUpdate);

        $observer = $this->createMock(Observer::class);
        $observer->method('getData')->willReturnMap([
            ['full_action_name', null, $fullActionName],
            ['layout', null, $layout],
        ]);

        return $observer;
    }
}
