<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Category\CategoryDataBuilder;

final class CategoryDataBuilderTest extends TestCase
{
    private CategoryCollectionFactory&MockObject $collectionFactory;
    private CategoryDataBuilder $sut;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CategoryCollectionFactory::class);

        $this->sut = new CategoryDataBuilder(
            $this->collectionFactory,
        );
    }

    public function test_build_returns_document_with_core_fields(): void
    {
        $category = $this->createCategoryMock(
            id: 5,
            name: 'Electronics',
            urlKey: 'electronics',
            path: '1/2/5',
            level: 2,
            productCount: 42,
            isActive: true,
        );

        $category->method('getUrl')->willReturn('https://example.com/electronics.html');
        $category->method('getImageUrl')->willReturn('');
        $category->method('getData')->willReturnMap([
            ['description', null, null],
        ]);

        $document = $this->sut->build($category, 1);

        self::assertSame('5', $document['id']);
        self::assertSame(5, $document['category_id']);
        self::assertSame('Electronics', $document['name']);
        self::assertSame('https://example.com/electronics.html', $document['url']);
        self::assertSame('1/2/5', $document['path']);
        self::assertSame(2, $document['level']);
        self::assertSame(42, $document['product_count']);
        self::assertTrue($document['is_active']);
    }

    public function test_build_includes_description_when_set(): void
    {
        $category = $this->createCategoryMock();
        $category->method('getUrl')->willReturn('');
        $category->method('getImageUrl')->willReturn('');
        $category->method('getData')->willReturnMap([
            ['description', null, '<p>Great electronics category</p>'],
        ]);

        $document = $this->sut->build($category, 1);

        self::assertSame('Great electronics category', $document['description']);
    }

    public function test_build_omits_description_when_null(): void
    {
        $category = $this->createCategoryMock();
        $category->method('getUrl')->willReturn('');
        $category->method('getImageUrl')->willReturn('');
        $category->method('getData')->willReturnMap([
            ['description', null, null],
        ]);

        $document = $this->sut->build($category, 1);

        self::assertArrayNotHasKey('description', $document);
    }

    public function test_build_omits_description_when_empty_string(): void
    {
        $category = $this->createCategoryMock();
        $category->method('getUrl')->willReturn('');
        $category->method('getImageUrl')->willReturn('');
        $category->method('getData')->willReturnMap([
            ['description', null, ''],
        ]);

        $document = $this->sut->build($category, 1);

        self::assertArrayNotHasKey('description', $document);
    }

    public function test_build_strips_html_from_description(): void
    {
        $category = $this->createCategoryMock();
        $category->method('getUrl')->willReturn('');
        $category->method('getImageUrl')->willReturn('');
        $category->method('getData')->willReturnMap([
            ['description', null, '<p>Best <strong>deals</strong> here.</p>'],
        ]);

        $document = $this->sut->build($category, 1);

        self::assertSame('Best deals here.', $document['description']);
    }

    public function test_build_includes_image_url_when_set(): void
    {
        $category = $this->createCategoryMock();
        $category->method('getUrl')->willReturn('');
        $category->method('getImageUrl')->willReturn('https://example.com/cat-image.jpg');
        $category->method('getData')->willReturnMap([
            ['description', null, null],
        ]);

        $document = $this->sut->build($category, 1);

        self::assertSame('https://example.com/cat-image.jpg', $document['image_url']);
    }

    public function test_build_omits_image_url_when_empty(): void
    {
        $category = $this->createCategoryMock();
        $category->method('getUrl')->willReturn('');
        $category->method('getImageUrl')->willReturn('');
        $category->method('getData')->willReturnMap([
            ['description', null, null],
        ]);

        $document = $this->sut->build($category, 1);

        self::assertArrayNotHasKey('image_url', $document);
    }

    public function test_get_category_collection_filters_active_categories(): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::once())
            ->method('addAttributeToFilter')
            ->with('is_active', 1);

        $collection->method('addAttributeToSelect');
        $collection->method('addFieldToFilter');
        $collection->method('setStoreId');

        $this->sut->getCategoryCollection([], 1);
    }

    public function test_get_category_collection_excludes_root_categories(): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('addAttributeToSelect');
        $collection->method('addAttributeToFilter');
        $collection->method('setStoreId');

        // Level >= 2 means we filter: level >= 2
        $collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with('level', ['gteq' => 2]);

        $this->sut->getCategoryCollection([], 1);
    }

    public function test_get_category_collection_filters_by_entity_ids_when_provided(): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('addAttributeToSelect');
        $collection->method('setStoreId');
        $collection->method('addAttributeToFilter');

        $collection->expects(self::exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection): CategoryCollection {
                return $collection;
            });

        $this->sut->getCategoryCollection([1, 2, 3], 1);
    }

    public function test_get_category_collection_does_not_filter_entity_ids_when_empty(): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->method('addAttributeToSelect');
        $collection->method('addAttributeToFilter');
        $collection->method('setStoreId');

        // Only the level filter should be called, not entity_id filter
        $collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with('level', ['gteq' => 2]);

        $this->sut->getCategoryCollection([], 1);
    }

    public function test_get_category_collection_sets_store_id(): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::once())
            ->method('setStoreId')
            ->with(3);

        $collection->method('addAttributeToSelect');
        $collection->method('addAttributeToFilter');
        $collection->method('addFieldToFilter');

        $this->sut->getCategoryCollection([], 3);
    }

    /**
     * @return Category&MockObject
     */
    private function createCategoryMock(
        int $id = 1,
        string $name = 'Test Category',
        string $urlKey = 'test-category',
        string $path = '1/2/1',
        int $level = 2,
        int $productCount = 10,
        bool $isActive = true,
    ): Category&MockObject {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);
        $category->method('getUrlKey')->willReturn($urlKey);
        $category->method('getPath')->willReturn($path);
        $category->method('getLevel')->willReturn($level);
        $category->method('getProductCount')->willReturn($productCount);
        $category->method('getIsActive')->willReturn($isActive);

        return $category;
    }
}
