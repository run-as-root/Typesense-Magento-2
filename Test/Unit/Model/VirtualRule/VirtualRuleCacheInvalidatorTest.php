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
}
