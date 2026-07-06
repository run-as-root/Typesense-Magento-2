<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

final class CategoryVirtualRuleResolverTest extends TestCase
{
    private CategoryVirtualRuleRepositoryInterface&MockObject $ruleRepository;
    private ConditionsToFilterByCompiler&MockObject $compiler;
    private CategoryCollectionFactory&MockObject $categoryCollectionFactory;
    private Json&MockObject $json;
    private CategoryVirtualRuleResolver $sut;

    protected function setUp(): void
    {
        $this->ruleRepository = $this->createMock(CategoryVirtualRuleRepositoryInterface::class);
        $this->compiler = $this->createMock(ConditionsToFilterByCompiler::class);
        $this->categoryCollectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $this->json = $this->createMock(Json::class);

        $this->sut = new CategoryVirtualRuleResolver(
            $this->ruleRepository,
            $this->compiler,
            $this->categoryCollectionFactory,
            $this->json,
        );
    }

    private function mockChildIds(array $ids): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getAllIds')->willReturn($ids);
        $this->categoryCollectionFactory->method('create')->willReturn($collection);
    }

    public function test_static_category_returns_category_ids_filter(): void
    {
        $this->ruleRepository->method('findByCategoryAndStore')->with(10, 1)->willReturn(null);

        self::assertSame('category_ids:=10', $this->sut->resolveFilter(10, 1));
    }

    public function test_non_virtual_rule_returns_category_ids_filter(): void
    {
        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('isVirtual')->willReturn(false);
        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);

        self::assertSame('category_ids:=10', $this->sut->resolveFilter(10, 1));
    }

    public function test_virtual_category_with_no_children_compiles_own_rule_only(): void
    {
        $this->mockChildIds([]);

        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('isVirtual')->willReturn(true);
        $rule->method('getCategoryId')->willReturn(10);
        $rule->method('getCompiledFilterCache')->willReturn(null);
        $rule->method('getConditionsSerialized')->willReturn('{"attribute":"color","operator":"==","value":"red"}');
        $rule->method('getVirtualCategoryRootId')->willReturn(null);

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);
        $this->json->method('unserialize')->willReturn(['attribute' => 'color', 'operator' => '==', 'value' => 'red']);
        $this->compiler->method('compile')->willReturn('color:=`red`');

        $rule->expects(self::once())->method('setCompiledFilterCache')->with('color:=`red`');
        $this->ruleRepository->expects(self::once())->method('save')->with($rule);

        self::assertSame('color:=`red`', $this->sut->resolveFilter(10, 1));
    }

    public function test_virtual_category_returns_cached_filter_without_recompiling(): void
    {
        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('isVirtual')->willReturn(true);
        $rule->method('getCompiledFilterCache')->willReturn('color:=`red`');

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);
        $this->compiler->expects(self::never())->method('compile');
        $this->ruleRepository->expects(self::never())->method('save');

        self::assertSame('color:=`red`', $this->sut->resolveFilter(10, 1));
    }

    public function test_virtual_category_merges_direct_child_filters(): void
    {
        $this->mockChildIds([20]);

        $parentRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $parentRule->method('isVirtual')->willReturn(true);
        $parentRule->method('getCategoryId')->willReturn(10);
        $parentRule->method('getCompiledFilterCache')->willReturn(null);
        $parentRule->method('getConditionsSerialized')->willReturn('{}');
        $parentRule->method('getVirtualCategoryRootId')->willReturn(null);

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [10, 1, $parentRule],
                [20, 1, null], // child 20 is a plain static category
            ]);

        $this->json->method('unserialize')->willReturn(['aggregator' => 'all', 'conditions' => []]);
        $this->compiler->method('compile')->willReturn(ConditionsToFilterByCompiler::NO_MATCH_FILTER);

        $result = $this->sut->resolveFilter(10, 1);

        self::assertSame(
            '(' . ConditionsToFilterByCompiler::NO_MATCH_FILTER . ') || (category_ids:=20)',
            $result,
        );
    }

    public function test_virtual_category_with_root_merges_root_filter_with_and(): void
    {
        $this->mockChildIds([]);

        $childRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $childRule->method('isVirtual')->willReturn(true);
        $childRule->method('getCategoryId')->willReturn(10);
        $childRule->method('getCompiledFilterCache')->willReturn(null);
        $childRule->method('getConditionsSerialized')->willReturn('{}');
        $childRule->method('getVirtualCategoryRootId')->willReturn(20);

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [10, 1, $childRule],
                [20, 1, null], // root is a plain static category
            ]);

        $this->json->method('unserialize')->willReturn(['aggregator' => 'all', 'conditions' => []]);
        $this->compiler->method('compile')->willReturn('color:=`red`');

        self::assertSame(
            '(category_ids:=20) && (color:=`red`)',
            $this->sut->resolveFilter(10, 1),
        );
    }

    public function test_circular_virtual_root_reference_throws_exception(): void
    {
        $this->mockChildIds([]);

        $ruleA = $this->createMock(CategoryVirtualRuleInterface::class);
        $ruleA->method('isVirtual')->willReturn(true);
        $ruleA->method('getCategoryId')->willReturn(10);
        $ruleA->method('getCompiledFilterCache')->willReturn(null);
        $ruleA->method('getConditionsSerialized')->willReturn('{}');
        $ruleA->method('getVirtualCategoryRootId')->willReturn(20);

        $ruleB = $this->createMock(CategoryVirtualRuleInterface::class);
        $ruleB->method('isVirtual')->willReturn(true);
        $ruleB->method('getCategoryId')->willReturn(20);
        $ruleB->method('getCompiledFilterCache')->willReturn(null);
        $ruleB->method('getConditionsSerialized')->willReturn('{}');
        $ruleB->method('getVirtualCategoryRootId')->willReturn(10);

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [10, 1, $ruleA],
                [20, 1, $ruleB],
            ]);

        $this->json->method('unserialize')->willReturn(['aggregator' => 'all', 'conditions' => []]);
        $this->compiler->method('compile')->willReturn(ConditionsToFilterByCompiler::NO_MATCH_FILTER);

        $this->expectException(LocalizedException::class);

        $this->sut->resolveFilter(10, 1);
    }
}
