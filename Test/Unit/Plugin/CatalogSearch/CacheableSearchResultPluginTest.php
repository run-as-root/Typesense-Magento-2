<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Plugin\CatalogSearch;

use Magento\Catalog\Model\Layer\Resolver;
use Magento\CatalogSearch\Controller\Result\Index;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Search\Model\Query;
use Magento\Search\Model\QueryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Plugin\CatalogSearch\CacheableSearchResultPlugin;

final class CacheableSearchResultPluginTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private RequestInterface&MockObject $request;
    private Resolver&MockObject $layerResolver;
    private PageFactory&MockObject $pageFactory;
    private QueryFactory&MockObject $queryFactory;
    private Query&MockObject $query;
    private Index&MockObject $controller;
    private CacheableSearchResultPlugin $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->layerResolver = $this->createMock(Resolver::class);
        $this->pageFactory = $this->createMock(PageFactory::class);
        $this->queryFactory = $this->createMock(QueryFactory::class);
        $this->query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['saveIncrementalPopularity'])
            ->addMethods(['getRedirect'])
            ->getMock();
        $this->queryFactory->method('get')->willReturn($this->query);
        $this->controller = $this->createMock(Index::class);

        $this->sut = new CacheableSearchResultPlugin(
            $this->config,
            $this->request,
            $this->layerResolver,
            $this->pageFactory,
            $this->queryFactory,
        );
    }

    public function test_search_controller_returns_cacheable_page_without_popularity_write_when_enabled(): void
    {
        $this->enable(true, true, true);
        $this->request->method('getParam')->with('q')->willReturn('kirschbrand');
        $this->query->method('getRedirect')->willReturn('');
        $this->query->expects(self::never())->method('saveIncrementalPopularity');
        $this->layerResolver->expects(self::once())->method('create')->with(Resolver::CATALOG_LAYER_SEARCH);
        $page = $this->createMock(Page::class);
        $this->pageFactory->expects(self::once())->method('create')->willReturn($page);

        $result = $this->sut->aroundExecute($this->controller, static function (): void {
            self::fail('The core search controller must not run.');
        });

        self::assertSame($page, $result);
    }

    #[DataProvider('fallThroughProvider')]
    public function test_search_controller_falls_through_when_disabled_or_query_empty(
        bool $moduleEnabled,
        bool $instantSearchEnabled,
        bool $cacheableEnabled,
        ?string $query,
    ): void {
        $this->enable($moduleEnabled, $instantSearchEnabled, $cacheableEnabled);
        $this->request->method('getParam')->with('q')->willReturn($query);
        $this->query->method('getRedirect')->willReturn('');
        $this->pageFactory->expects(self::never())->method('create');
        $this->layerResolver->expects(self::never())->method('create');

        self::assertSame('core result', $this->sut->aroundExecute($this->controller, static fn () => 'core result'));
    }

    /** @return array<string, array{bool, bool, bool, ?string}> */
    public static function fallThroughProvider(): array
    {
        return [
            'module disabled' => [false, true, true, 'kirschbrand'],
            'instant search disabled' => [true, false, true, 'kirschbrand'],
            'cacheable search page disabled' => [true, true, false, 'kirschbrand'],
            'query missing' => [true, true, true, null],
            'query blank' => [true, true, true, '   '],
        ];
    }

    public function test_search_term_with_redirect_url_falls_through_to_core_redirect(): void
    {
        $this->enable(true, true, true);
        $this->request->method('getParam')->with('q')->willReturn('gin');
        $this->query->method('getRedirect')->willReturn('https://prinz.test/gin.html');
        $this->pageFactory->expects(self::never())->method('create');

        self::assertSame('core result', $this->sut->aroundExecute($this->controller, static fn () => 'core result'));
    }

    private function enable(bool $module, bool $instantSearch, bool $cacheable): void
    {
        $this->config->method('isEnabled')->willReturn($module);
        $this->config->method('isInstantSearchEnabled')->willReturn($instantSearch);
        $this->config->method('isCacheableSearchPageEnabled')->willReturn($cacheable);
    }
}
