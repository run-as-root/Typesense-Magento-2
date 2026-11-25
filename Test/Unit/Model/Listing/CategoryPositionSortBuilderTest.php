<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Listing;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Boost\BoostSortBuilder;
use RunAsRoot\TypeSense\Model\Listing\CategoryPositionSortBuilder;
use RunAsRoot\TypeSense\Model\Listing\CollectionFieldProvider;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;

final class CategoryPositionSortBuilderTest extends TestCase
{
    private CollectionFieldProvider&MockObject $fieldProvider;
    private BoostSortBuilder&MockObject $boostSortBuilder;
    private CategoryPositionSortBuilder $sut;

    protected function setUp(): void
    {
        $this->fieldProvider = $this->createMock(CollectionFieldProvider::class);
        $this->boostSortBuilder = $this->createMock(BoostSortBuilder::class);

        $this->sut = new CategoryPositionSortBuilder($this->fieldProvider, $this->boostSortBuilder);
    }

    public function test_sorts_by_the_category_position_ascending(): void
    {
        $this->fieldProvider->method('hasField')->with('rar_product_de', 'category_position_42')->willReturn(true);

        self::assertSame('category_position_42:asc', $this->sut->build(42, 3, 'rar_product_de'));
    }

    public function test_category_boosting_rules_break_ties_after_the_position(): void
    {
        $this->fieldProvider->method('hasField')->willReturn(true);
        $this->boostSortBuilder->method('buildEvalClause')
            ->with(SearchRequestParamsBuilder::CONTEXT_CATEGORY, 3)
            ->willReturn('_eval([(sku:=`a`):10])');

        self::assertSame(
            'category_position_42:asc,_eval([(sku:=`a`):10]):desc',
            $this->sut->build(42, 3, 'rar_product_de'),
        );
    }

    public function test_no_position_sort_while_the_collection_has_no_field_for_the_category(): void
    {
        $this->fieldProvider->method('hasField')->willReturn(false);
        $this->boostSortBuilder->expects(self::never())->method('buildEvalClause');

        self::assertNull($this->sut->build(42, 3, 'rar_product_de'));
    }
}
