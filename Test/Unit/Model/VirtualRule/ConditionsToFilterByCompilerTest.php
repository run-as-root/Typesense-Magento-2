<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

final class ConditionsToFilterByCompilerTest extends TestCase
{
    private ConditionsToFilterByCompiler $sut;

    protected function setUp(): void
    {
        $this->sut = new ConditionsToFilterByCompiler();
    }

    public function test_compiles_equals_operator_with_string_value(): void
    {
        $node = ['attribute' => 'color', 'operator' => '==', 'value' => 'red'];

        self::assertSame('color:=`red`', $this->sut->compile($node));
    }

    public function test_compiles_numeric_value_without_quoting(): void
    {
        $node = ['attribute' => 'price', 'operator' => '<', 'value' => '50'];

        self::assertSame('price:<50', $this->sut->compile($node));
    }

    public function test_compiles_not_equals_operator(): void
    {
        $node = ['attribute' => 'status', 'operator' => '!=', 'value' => 'disabled'];

        self::assertSame('status:!=`disabled`', $this->sut->compile($node));
    }

    public function test_compiles_greater_or_equal_operator(): void
    {
        $node = ['attribute' => 'price', 'operator' => '>=', 'value' => '10'];

        self::assertSame('price:>=10', $this->sut->compile($node));
    }

    public function test_compiles_in_list_operator(): void
    {
        $node = ['attribute' => 'color', 'operator' => '()', 'value' => 'red,blue,green'];

        self::assertSame('color:[`red`,`blue`,`green`]', $this->sut->compile($node));
    }

    public function test_compiles_not_in_list_operator(): void
    {
        $node = ['attribute' => 'color', 'operator' => '!()', 'value' => 'red,blue'];

        self::assertSame('color:!=[`red`,`blue`]', $this->sut->compile($node));
    }

    public function test_compiles_all_aggregator_with_and(): void
    {
        $node = [
            'aggregator' => 'all',
            'conditions' => [
                ['attribute' => 'color', 'operator' => '==', 'value' => 'red'],
                ['attribute' => 'price', 'operator' => '<', 'value' => '50'],
            ],
        ];

        self::assertSame('(color:=`red`) && (price:<50)', $this->sut->compile($node));
    }

    public function test_compiles_any_aggregator_with_or(): void
    {
        $node = [
            'aggregator' => 'any',
            'conditions' => [
                ['attribute' => 'color', 'operator' => '==', 'value' => 'red'],
                ['attribute' => 'color', 'operator' => '==', 'value' => 'blue'],
            ],
        ];

        self::assertSame('(color:=`red`) || (color:=`blue`)', $this->sut->compile($node));
    }

    public function test_compiles_nested_combine_groups(): void
    {
        $node = [
            'aggregator' => 'all',
            'conditions' => [
                ['attribute' => 'gender', 'operator' => '==', 'value' => 'women'],
                [
                    'aggregator' => 'any',
                    'conditions' => [
                        ['attribute' => 'color', 'operator' => '==', 'value' => 'red'],
                        ['attribute' => 'color', 'operator' => '==', 'value' => 'blue'],
                    ],
                ],
            ],
        ];

        self::assertSame(
            '(gender:=`women`) && ((color:=`red`) || (color:=`blue`))',
            $this->sut->compile($node),
        );
    }

    public function test_empty_combine_compiles_to_no_match_filter(): void
    {
        $node = ['aggregator' => 'all', 'conditions' => []];

        self::assertSame(ConditionsToFilterByCompiler::NO_MATCH_FILTER, $this->sut->compile($node));
    }

    public function test_boolean_false_value_is_not_dropped(): void
    {
        // Guards against the known ElasticSuite bug class where falsy condition
        // values get silently treated as "empty" during compilation.
        $node = ['attribute' => 'is_active', 'operator' => '==', 'value' => false];

        self::assertSame('is_active:=false', $this->sut->compile($node));
    }

    public function test_string_zero_value_is_not_dropped(): void
    {
        $node = ['attribute' => 'qty', 'operator' => '==', 'value' => '0'];

        self::assertSame('qty:=0', $this->sut->compile($node));
    }

    public function test_missing_attribute_throws_exception(): void
    {
        $this->expectException(LocalizedException::class);

        $this->sut->compile(['attribute' => '', 'operator' => '==', 'value' => 'red']);
    }

    public function test_unsupported_operator_throws_exception(): void
    {
        $this->expectException(LocalizedException::class);

        $this->sut->compile(['attribute' => 'color', 'operator' => '~~', 'value' => 'red']);
    }

    public function test_non_scalar_value_throws_exception_for_scalar_operator(): void
    {
        $this->expectException(LocalizedException::class);

        $this->sut->compile(['attribute' => 'price', 'operator' => '==', 'value' => ['a', 'b']]);
    }

    public function test_value_containing_backtick_throws_exception(): void
    {
        $this->expectException(LocalizedException::class);

        $this->sut->compile(['attribute' => 'name', 'operator' => '==', 'value' => 'A`B']);
    }
}
