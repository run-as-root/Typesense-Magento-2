<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryListingFilterBuilder;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;

final class CategoryListingFilterBuilderTest extends TestCase
{
    private CategoryVirtualRuleResolver&MockObject $resolver;
    private CategoryListingFilterBuilder $sut;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(CategoryVirtualRuleResolver::class);
        $this->sut = new CategoryListingFilterBuilder($this->resolver);
    }

    public function test_build_appends_visibility_clause_to_resolved_category_filter(): void
    {
        $this->resolver->method('resolveFilter')->with(5, 2)->willReturn('category_ids:=5');

        self::assertSame('(category_ids:=5) && visibility:[2,4]', $this->sut->build(5, 2));
    }

    public function test_build_contains_visibility_clause_exactly_once(): void
    {
        $this->resolver->method('resolveFilter')->willReturn('category_ids:=5');

        self::assertSame(1, substr_count($this->sut->build(5, 2), 'visibility:[2,4]'));
    }

    public function test_build_groups_or_filters_so_visibility_applies_to_every_branch(): void
    {
        $this->resolver->method('resolveFilter')->willReturn('(color:=`red`) || (category_ids:=9)');

        self::assertSame(
            '((color:=`red`) || (category_ids:=9)) && visibility:[2,4]',
            $this->sut->build(8, 1),
        );
    }
}
