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
    // id => [name, level, parent id, is_anchor, is_active]
    private const array TREE = [
        1 => ['Root Catalog', 0, 0, true, true],
        2 => ['Default Category', 1, 1, true, true],
        10 => ['Fruit', 2, 2, true, true],
        20 => ['Stone Fruit', 3, 10, true, true],
        30 => ['Plum', 4, 20, true, true],
        31 => ['Cherry', 4, 20, true, true],
        40 => ['Damson', 5, 30, true, true],
        50 => ['Vegetables', 2, 2, true, true],
        60 => ['Roots', 3, 50, false, true],
        70 => ['Carrot', 4, 60, true, true],
        80 => ['Seasonal', 2, 2, true, false],
        81 => ['Pumpkin', 3, 80, true, true],
        82 => ['Hidden', 3, 50, true, false],
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
            [$name, $level, $parentId, $isAnchor, $isActive] = self::TREE[$id];
            $category = $this->createMock(Category::class);
            $category->method('getId')->willReturn((string) $id);
            $category->method('getData')->willReturnCallback(
                static fn (string $key = '') => $key === 'is_anchor' ? (int) $isAnchor : null,
            );
            $category->method('getIsActive')->willReturn($isActive);
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
        self::assertSame([30, 10, 20], $data['category_ids']);
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

    public function test_anchor_ancestors_are_added_to_category_ids_like_magentos_anchor_index(): void
    {
        // Magento lists a product in every active anchor ancestor of an active assigned category, also above a
        // non-anchor one (Roots); the store root is not a listing.
        self::assertSame([70, 50], $this->sut->getCategoryData($this->product([70]))['category_ids']);
        // Inactive anchor ancestors (Seasonal) are skipped.
        self::assertSame([81], $this->sut->getCategoryData($this->product([81]))['category_ids']);
        // An inactive assigned category passes nothing up.
        self::assertSame([82], $this->sut->getCategoryData($this->product([82]))['category_ids']);
        self::assertSame([40, 31, 10, 20, 30], $this->sut->getCategoryData($this->product([40, 31]))['category_ids']);
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
