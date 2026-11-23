<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
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
    // store id => id => [name, is_active]: store-view values that differ from store 1.
    private const array STORE_VALUES = [
        2 => [10 => ['Obst', true], 20 => ['Steinobst', false]],
    ];

    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private int $storeId = 1;
    /** @var list<array{int, int}> */
    private array $loads = [];
    private CategoryResolver $sut;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryRepository->method('get')->willReturnCallback(function (int $id, ?int $storeId = null): Category {
            $this->loads[] = [$storeId, $id];
            if (!isset(self::TREE[$id])) {
                throw new NoSuchEntityException();
            }
            [$name, $level, $parentId, $isAnchor, $isActive] = self::TREE[$id];
            [$name, $isActive] = self::STORE_VALUES[$storeId][$id] ?? [$name, $isActive];
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
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function (): Store {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($this->storeId);

            return $store;
        });
        $this->sut = new CategoryResolver($this->categoryRepository, $storeManager);
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

    public function test_each_category_is_loaded_once_per_store_with_that_store_id(): void
    {
        $this->sut->getCategoryData($this->product([30, 31]));
        $this->sut->getCategoryData($this->product([31]));
        $this->storeId = 2;
        $this->sut->getCategoryData($this->product([31]));

        self::assertSame([[1, 30], [1, 20], [1, 10], [1, 31], [2, 31], [2, 20], [2, 10]], $this->loads);
    }

    public function test_names_and_active_flags_come_from_the_store_being_indexed(): void
    {
        $storeOne = $this->sut->getCategoryData($this->product([30]));
        $this->storeId = 2;
        $storeTwo = $this->sut->getCategoryData($this->product([30]));

        self::assertSame(['Fruit'], $storeOne['categories.lvl0']);
        self::assertSame([30, 10, 20], $storeOne['category_ids']);
        // Store 2 renames Fruit and disables Stone Fruit: no lvl1 path and no anchor id of its own, but the
        // deeper path keeps its name, as SubcategoryFacetResolver builds the prefix from the same names.
        self::assertSame(['Obst'], $storeTwo['categories.lvl0']);
        self::assertSame([], $storeTwo['categories.lvl1']);
        self::assertSame(['Obst > Steinobst > Plum'], $storeTwo['categories.lvl2']);
        self::assertSame([30, 10], $storeTwo['category_ids']);
    }

    public function test_inactive_categories_add_no_name_and_no_path_but_keep_their_assigned_id(): void
    {
        $hidden = $this->sut->getCategoryData($this->product([82]));
        self::assertSame([], $hidden['categories']);
        self::assertSame([], $hidden['categories.lvl0']);
        self::assertSame([], $hidden['categories.lvl1']);
        self::assertSame([82], $hidden['category_ids']);

        $pumpkin = $this->sut->getCategoryData($this->product([81]));
        self::assertSame(['Pumpkin'], $pumpkin['categories']);
        self::assertSame([], $pumpkin['categories.lvl0']);
        self::assertSame(['Seasonal > Pumpkin'], $pumpkin['categories.lvl1']);
        self::assertSame([81], $pumpkin['category_ids']);
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
