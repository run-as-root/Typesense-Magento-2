<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\CategoryResolver;

final class CategoryResolverTest extends TestCase
{
    // id => [name, level, parent id]
    private const array TREE = [
        1 => ['Root Catalog', 0, 0],
        2 => ['Default Category', 1, 1],
        10 => ['Fruit', 2, 2],
        20 => ['Stone Fruit', 3, 10],
        30 => ['Plum', 4, 20],
        31 => ['Cherry', 4, 20],
        40 => ['Damson', 5, 30],
    ];

    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private CategoryResolver $sut;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryRepository->method('get')->willReturnCallback(function (int $id): Category {
            if (!isset(self::TREE[$id])) {
                throw new NoSuchEntityException();
            }
            [$name, $level, $parentId] = self::TREE[$id];
            $category = $this->createMock(Category::class);
            $category->method('getName')->willReturn($name);
            $category->method('getLevel')->willReturn($level);
            $category->method('getParentId')->willReturn($parentId);

            return $category;
        });
        $this->sut = new CategoryResolver($this->categoryRepository);
    }

    public function test_a_product_in_a_leaf_category_gets_every_ancestor_path_at_its_own_level(): void
    {
        $data = $this->sut->getCategoryData($this->product([30]));

        self::assertSame(['Plum'], $data['categories']);
        self::assertSame([30], $data['category_ids']);
        self::assertSame(['Fruit'], $data['categories.lvl0']);
        self::assertSame(['Fruit > Stone Fruit'], $data['categories.lvl1']);
        self::assertSame(['Fruit > Stone Fruit > Plum'], $data['categories.lvl2']);
    }

    public function test_deeper_levels_add_each_ancestor_path_to_lvl2_and_shared_ancestors_are_listed_once(): void
    {
        $data = $this->sut->getCategoryData($this->product([40, 31, 20]));

        self::assertSame(['Fruit'], $data['categories.lvl0']);
        self::assertSame(['Fruit > Stone Fruit'], $data['categories.lvl1']);
        self::assertSame([
            'Fruit > Stone Fruit > Plum',
            'Fruit > Stone Fruit > Plum > Damson',
            'Fruit > Stone Fruit > Cherry',
        ], $data['categories.lvl2']);
    }

    public function test_root_categories_and_missing_categories_add_no_path(): void
    {
        $data = $this->sut->getCategoryData($this->product([2, 99]));

        self::assertSame([2], $data['category_ids']);
        self::assertSame([], $data['categories.lvl0']);
        self::assertSame([], $data['categories.lvl1']);
        self::assertSame([], $data['categories.lvl2']);
    }

    public function test_each_category_is_loaded_once(): void
    {
        $this->categoryRepository->expects(self::exactly(4))->method('get');

        $this->sut->getCategoryData($this->product([30, 31]));
        $this->sut->getCategoryData($this->product([31]));
    }

    /**
     * @param list<int> $categoryIds
     */
    private function product(array $categoryIds): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getCategoryIds')->willReturn($categoryIds);

        return $product;
    }
}
