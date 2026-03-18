<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\CmsPage;

use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\Collection as PageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\CmsPage\CmsPageDataBuilder;

final class CmsPageDataBuilderTest extends TestCase
{
    private PageCollectionFactory&MockObject $collectionFactory;
    private CmsPageDataBuilder $sut;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(PageCollectionFactory::class);

        $this->sut = new CmsPageDataBuilder(
            $this->collectionFactory,
        );
    }

    public function test_build_returns_document_with_core_fields(): void
    {
        $page = $this->createPageMock(
            id: 10,
            title: 'About Us',
            content: 'We are a company.',
            identifier: 'about-us',
            isActive: true,
        );

        $document = $this->sut->build($page, 1);

        self::assertSame('10', $document['id']);
        self::assertSame(10, $document['page_id']);
        self::assertSame('About Us', $document['title']);
        self::assertSame('We are a company.', $document['content']);
        self::assertSame('about-us', $document['url_key']);
        self::assertTrue($document['is_active']);
    }

    public function test_build_strips_html_from_content(): void
    {
        $page = $this->createPageMock(
            content: '<p>Our <strong>mission</strong> is to serve.</p>',
        );

        $document = $this->sut->build($page, 1);

        self::assertSame('Our mission is to serve.', $document['content']);
    }

    public function test_get_page_collection_applies_store_filter(): void
    {
        $collection = $this->createMock(PageCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::once())
            ->method('addStoreFilter')
            ->with(3);

        $collection->method('addFieldToFilter');

        $this->sut->getPageCollection([], 3);
    }

    public function test_get_page_collection_filters_active_pages(): void
    {
        $collection = $this->createMock(PageCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('addStoreFilter');

        $collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with('is_active', 1);

        $this->sut->getPageCollection([], 1);
    }

    public function test_get_page_collection_filters_by_entity_ids_when_provided(): void
    {
        $collection = $this->createMock(PageCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('addStoreFilter');

        $collection->expects(self::exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection): PageCollection {
                return $collection;
            });

        $this->sut->getPageCollection([1, 2, 3], 1);
    }

    public function test_get_page_collection_does_not_filter_entity_ids_when_empty(): void
    {
        $collection = $this->createMock(PageCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('addStoreFilter');

        // Only the is_active filter should be called, not the page_id filter
        $collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with('is_active', 1);

        $this->sut->getPageCollection([], 1);
    }

    /**
     * @return Page&MockObject
     */
    private function createPageMock(
        int $id = 1,
        string $title = 'Test Page',
        string $content = 'Test content.',
        string $identifier = 'test-page',
        bool $isActive = true,
    ): Page&MockObject {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn($id);
        $page->method('getTitle')->willReturn($title);
        $page->method('getContent')->willReturn($content);
        $page->method('getIdentifier')->willReturn($identifier);
        $page->method('isActive')->willReturn($isActive);

        return $page;
    }
}
