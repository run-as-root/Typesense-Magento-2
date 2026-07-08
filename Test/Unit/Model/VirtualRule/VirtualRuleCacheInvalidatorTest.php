<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\VirtualRuleCacheInvalidator;

final class VirtualRuleCacheInvalidatorTest extends TestCase
{
    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private CategoryVirtualRuleRepositoryInterface&MockObject $ruleRepository;
    private VirtualRuleCacheInvalidator $sut;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->ruleRepository = $this->createMock(CategoryVirtualRuleRepositoryInterface::class);

        $this->sut = new VirtualRuleCacheInvalidator(
            $this->categoryRepository,
            $this->ruleRepository,
        );
    }

    public function test_clears_cache_for_category_and_ancestors_with_rules(): void
    {
        // Path: root(1) / grandparent(2) / parent(5) / this category(30)
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/2/5/30');
        $this->categoryRepository->method('get')->with(30)->willReturn($category);

        $leafRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $leafRule->method('getCompiledFilterCache')->willReturn('leaf-filter');

        $ancestorRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $ancestorRule->method('getCompiledFilterCache')->willReturn('ancestor-filter');

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [30, 1, $leafRule],
                [2, 1, null],   // no rule row — nothing to clear
                [5, 1, $ancestorRule],
            ]);

        $leafRule->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $ancestorRule->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $this->ruleRepository->expects(self::exactly(2))->method('save');

        $cleared = $this->sut->invalidate(30, 1);

        self::assertSame([30, 5], $cleared);
    }

    public function test_returns_empty_array_when_no_rule_rows_exist(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/30');
        $this->categoryRepository->method('get')->with(30)->willReturn($category);

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn(null);
        $this->ruleRepository->expects(self::never())->method('save');

        self::assertSame([], $this->sut->invalidate(30, 1));
    }

    public function test_skips_categories_whose_cache_is_already_null(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/30');
        $this->categoryRepository->method('get')->with(30)->willReturn($category);

        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('getCompiledFilterCache')->willReturn(null);
        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);

        $rule->expects(self::never())->method('setCompiledFilterCache');
        $this->ruleRepository->expects(self::never())->method('save');

        self::assertSame([], $this->sut->invalidate(30, 1));
    }

    public function test_clears_mirror_category_cache_when_root_category_changes(): void
    {
        // Category A (30) is edited; category B (40) mirrors A via virtual_category_root_id.
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/30');
        $this->categoryRepository->method('get')->with(30)->willReturn($category);

        // A's own cache was already nulled by Save.php before invalidate() runs.
        $this->ruleRepository->method('findByCategoryAndStore')->willReturn(null);

        $mirrorRuleB = $this->createMock(CategoryVirtualRuleInterface::class);
        $mirrorRuleB->method('getCategoryId')->willReturn(40);
        $mirrorRuleB->method('getCompiledFilterCache')->willReturn('b-filter');

        $this->ruleRepository->method('findRulesByVirtualCategoryRootId')
            ->willReturnMap([
                [30, 1, [$mirrorRuleB]],
                [40, 1, []],
            ]);

        $mirrorRuleB->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $this->ruleRepository->expects(self::once())->method('save')->with($mirrorRuleB);

        $cleared = $this->sut->invalidate(30, 1);

        self::assertSame([40], $cleared);
    }

    public function test_cascades_through_mirror_of_a_mirror_chain(): void
    {
        // C (30) mirrors B (20), which mirrors A (10). Editing A must clear both B and C.
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/10');
        $this->categoryRepository->method('get')->with(10)->willReturn($category);

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn(null);

        $mirrorRuleB = $this->createMock(CategoryVirtualRuleInterface::class);
        $mirrorRuleB->method('getCategoryId')->willReturn(20);
        $mirrorRuleB->method('getCompiledFilterCache')->willReturn('b-filter');

        $mirrorRuleC = $this->createMock(CategoryVirtualRuleInterface::class);
        $mirrorRuleC->method('getCategoryId')->willReturn(30);
        $mirrorRuleC->method('getCompiledFilterCache')->willReturn('c-filter');

        $this->ruleRepository->method('findRulesByVirtualCategoryRootId')
            ->willReturnMap([
                [10, 1, [$mirrorRuleB]],
                [20, 1, [$mirrorRuleC]],
                [30, 1, []],
            ]);

        $mirrorRuleB->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $mirrorRuleC->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $this->ruleRepository->expects(self::exactly(2))->method('save');

        $cleared = $this->sut->invalidate(10, 1);

        self::assertSame([20, 30], $cleared);
    }

    public function test_defensive_cycle_in_mirror_graph_does_not_infinite_loop(): void
    {
        // A (10) mirrors B (20) and B (20) mirrors A (10) - a cycle the invalidator's own
        // traversal must not loop on, regardless of whether the resolver would allow saving it.
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/10');
        $this->categoryRepository->method('get')->with(10)->willReturn($category);

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn(null);

        $mirrorRuleB = $this->createMock(CategoryVirtualRuleInterface::class);
        $mirrorRuleB->method('getCategoryId')->willReturn(20);
        $mirrorRuleB->method('getCompiledFilterCache')->willReturn('b-filter');

        $cyclicRuleA = $this->createMock(CategoryVirtualRuleInterface::class);
        $cyclicRuleA->method('getCategoryId')->willReturn(10);
        $cyclicRuleA->method('getCompiledFilterCache')->willReturn('a-filter');

        $this->ruleRepository->method('findRulesByVirtualCategoryRootId')
            ->willReturnMap([
                [10, 1, [$mirrorRuleB]],
                [20, 1, [$cyclicRuleA]],
            ]);

        // 10 was already visited (it's the category being edited), so the cyclic back-reference
        // to it must be skipped entirely - no second clear/save on the category-10 rule row.
        $cyclicRuleA->expects(self::never())->method('setCompiledFilterCache');
        $mirrorRuleB->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $this->ruleRepository->expects(self::once())->method('save')->with($mirrorRuleB);

        $cleared = $this->sut->invalidate(10, 1);

        self::assertSame([20], $cleared);
    }
}
