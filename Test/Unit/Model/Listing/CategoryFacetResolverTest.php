<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Listing;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetResolver;

final class CategoryFacetResolverTest extends TestCase
{
    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private CollectionFactory&MockObject $collectionFactory;
    private Collection&MockObject $children;
    private Collection&MockObject $allCategories;
    private CategoryFacetResolver $sut;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->children = $this->createMock(Collection::class);
        $this->allCategories = $this->createMock(Collection::class);
        $this->allCategories->method('getSize')->willReturn(350);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls($this->children, $this->allCategories);
        $this->sut = new CategoryFacetResolver($this->categoryRepository, $this->collectionFactory);
    }

    public function test_category_facet_lists_the_active_children_with_store_names_in_position_order(): void
    {
        $this->children->expects(self::once())->method('setStoreId')->with(3)->willReturnSelf();
        $this->children->expects(self::once())->method('addAttributeToSelect')->with('name')->willReturnSelf();
        $this->children->expects(self::once())->method('addAttributeToFilter')->with('is_active', ['eq' => 1])->willReturnSelf();
        $this->children->expects(self::once())->method('addFieldToFilter')->with('parent_id', ['eq' => 15])->willReturnSelf();
        $this->children->expects(self::once())->method('setOrder')->with('position', 'ASC')->willReturnSelf();
        $this->givenChildren([[22, 'Kräuterlikör'], [7, 'Kräuter-Schnaps & Likör']]);

        self::assertSame([
            'field' => 'category_ids',
            'param' => 'cat',
            'label' => 'Category',
            'type' => 'category',
            'searchable' => false,
            'boolean' => false,
            'numeric' => 'int32',
            'values' => [
                ['value' => '22', 'label' => 'Kräuterlikör'],
                ['value' => '7', 'label' => 'Kräuter-Schnaps & Likör'],
            ],
            // Every category id can be on the products, so no child can fall outside the facet values.
            'maxValues' => 350,
        ], $this->sut->resolve($this->category(15, true), 3));
    }

    public function test_no_category_facet_without_active_children(): void
    {
        $this->givenFluentChildren(7);
        $this->givenChildren([]);

        self::assertNull($this->sut->resolve($this->category(7, true), 3));
    }

    public function test_no_category_facet_below_an_inactive_category(): void
    {
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertNull($this->sut->resolve($this->category(15, false), 3));
    }

    public function test_search_facet_lists_the_top_level_categories_below_the_store_root(): void
    {
        $this->categoryRepository->method('get')->with(2, 3)->willReturn($this->category(2, true));
        $this->givenFluentChildren(2);
        $this->givenChildren([[15, 'Kräuter, Rum & Punsch']]);

        $facet = $this->sut->resolveForStoreRoot(2, 3);

        self::assertSame([['value' => '15', 'label' => 'Kräuter, Rum & Punsch']], $facet['values'] ?? null);
    }

    public function test_no_search_facet_when_the_store_root_is_missing(): void
    {
        $this->categoryRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertNull($this->sut->resolveForStoreRoot(99, 3));
    }

    private function givenFluentChildren(int $parentId): void
    {
        $this->children->method('setStoreId')->willReturnSelf();
        $this->children->method('addAttributeToSelect')->willReturnSelf();
        $this->children->method('addAttributeToFilter')->willReturnSelf();
        $this->children->expects(self::once())->method('addFieldToFilter')->with('parent_id', ['eq' => $parentId])->willReturnSelf();
        $this->children->method('setOrder')->willReturnSelf();
    }

    /** @param list<array{int, string}> $rows */
    private function givenChildren(array $rows): void
    {
        $children = [];
        foreach ($rows as [$id, $name]) {
            $child = $this->createMock(Category::class);
            $child->method('getId')->willReturn((string) $id);
            $child->method('getName')->willReturn($name);
            $children[] = $child;
        }
        $this->children->method('getIterator')->willReturn(new \ArrayIterator($children));
    }

    private function category(int $id, bool $isActive): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn((string) $id);
        $category->method('getIsActive')->willReturn($isActive);

        return $category;
    }
}
