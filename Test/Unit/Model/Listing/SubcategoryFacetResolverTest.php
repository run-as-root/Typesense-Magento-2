<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Listing;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Listing\SubcategoryFacetResolver;

final class SubcategoryFacetResolverTest extends TestCase
{
    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private SubcategoryFacetResolver $sut;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryRepository->method('get')->willReturnCallback(
            fn (int $id, int $storeId): Category => $this->category($id, ['10' => 'Spirituosen', '20' => 'Gin'][$id], 0, []),
        );
        $this->sut = new SubcategoryFacetResolver($this->categoryRepository);
    }

    /**
     * @param list<string> $pathIds
     */
    #[DataProvider('categoryLevels')]
    public function test_subcategory_facet_uses_the_category_path_field_of_the_next_level(
        int $level,
        array $pathIds,
        string $expectedField,
        string $expectedPrefix,
    ): void {
        $category = $this->category((int) end($pathIds), 'Dry Gin', 3, $pathIds, $level);

        self::assertSame([
            'field' => $expectedField,
            'label' => 'Category',
            'type' => 'subcategory',
            'searchable' => false,
            'boolean' => false,
            'numeric' => false,
            'prefix' => $expectedPrefix,
        ], $this->sut->resolve($category, 1));
    }

    /**
     * @return array<string, array{int, list<string>, string, string}>
     */
    public static function categoryLevels(): array
    {
        return [
            // Children of a top-level category are level 3, indexed as "Top > Child" in categories.lvl1.
            'top level' => [2, ['1', '2', '30'], 'categories.lvl1', 'Dry Gin > '],
            'level 3' => [3, ['1', '2', '10', '30'], 'categories.lvl2', 'Spirituosen > Dry Gin > '],
            // categories.lvl2 holds every level from 4 down, as full paths.
            'level 4' => [4, ['1', '2', '10', '20', '30'], 'categories.lvl2', 'Spirituosen > Gin > Dry Gin > '],
        ];
    }

    public function test_no_subcategory_facet_without_children_or_above_the_top_level(): void
    {
        self::assertNull($this->sut->resolve($this->category(30, 'Dry Gin', 0, ['1', '2', '30'], 2), 1));
        self::assertNull($this->sut->resolve($this->category(2, 'Default Category', 5, ['1', '2'], 1), 1));
    }

    public function test_no_subcategory_facet_when_an_ancestor_cannot_be_loaded(): void
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException());
        $sut = new SubcategoryFacetResolver($repository);

        self::assertNull($sut->resolve($this->category(30, 'Dry Gin', 3, ['1', '2', '10', '30'], 3), 1));
    }

    /**
     * @param list<string> $pathIds
     */
    private function category(int $id, string $name, int $childrenCount, array $pathIds, int $level = 0): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn((string) $id);
        $category->method('getName')->willReturn($name);
        $category->method('getChildrenCount')->willReturn($childrenCount);
        $category->method('getPathIds')->willReturn($pathIds);
        $category->method('getLevel')->willReturn($level);

        return $category;
    }
}
