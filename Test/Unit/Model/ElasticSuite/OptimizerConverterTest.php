<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\ElasticSuite;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\ElasticSuite\CatalogLookup;
use RunAsRoot\TypeSense\Model\ElasticSuite\OptimizerConverter;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Combine;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

final class OptimizerConverterTest extends TestCase
{
    private const ES_COMBINE = 'Smile\\ElasticsuiteVirtualCategory\\Model\\Rule\\Condition\\Combine';
    private const ES_PRODUCT = 'Smile\\ElasticsuiteVirtualCategory\\Model\\Rule\\Condition\\Product';

    private CatalogLookup&MockObject $catalogLookup;
    private TypeSenseConfigInterface&MockObject $config;
    private OptimizerConverter $sut;

    protected function setUp(): void
    {
        $this->catalogLookup = $this->createMock(CatalogLookup::class);
        $this->catalogLookup->method('findSkusContaining')->willReturnCallback(
            static fn (string $value): array => match ($value) {
                '10148-group' => ['10148-group'],
                '10029-group' => ['10029-group', '10029-group-023', 'x10029-group'],
                '100' => ['100', '1001', '10029-group'],
                'many' => array_map(static fn (int $i): string => 'many-' . $i, range(1, 21)),
                'truncated' => array_fill(0, CatalogLookup::SKU_LIMIT, 'truncated-x'),
                default => [],
            },
        );
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getAdditionalAttributes')->willReturn(['trade_name', 'color']);

        $this->sut = new OptimizerConverter($this->catalogLookup, $this->config, new ConditionsToFilterByCompiler());
    }

    public function test_constant_score_sku_contains_on_an_exact_sku_becomes_an_is_one_of_condition(): void
    {
        $result = $this->sut->convert(
            $this->optimizer(['conditions' => [$this->leaf('sku', '{}', '10148-group')]]),
            ['quick_search_container' => 0],
            null,
        );

        self::assertNull($result->skipReason);
        self::assertNull($result->inactiveReason);
        self::assertSame([], $result->notes);
        self::assertSame(1000, $result->weight);
        self::assertTrue($result->applyToSearch);
        self::assertFalse($result->applyToCategory);
        self::assertSame([
            'type' => Combine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'is_value_processed' => null,
            'aggregator' => 'all',
            'conditions' => [[
                'type' => Product::class,
                'attribute' => 'sku',
                'operator' => '()',
                'value' => '10148-group',
                'is_value_processed' => false,
            ]],
        ], $result->conditions);
    }

    public function test_sku_contains_becomes_is_one_of_the_skus_containing_the_value_as_whole_words(): void
    {
        $result = $this->sut->convert(
            $this->optimizer(['conditions' => [$this->leaf('sku', '{}', '10029-group, 100')]]),
            ['quick_search_container' => 0],
            null,
        );

        self::assertSame('10029-group, 10029-group-023, 100', $result->conditions['conditions'][0]['value']);
        self::assertSame(
            ['sku contains "10029-group" became is one of 10029-group, 10029-group-023 (the SKUs containing it today)'],
            $result->notes,
        );
    }

    public function test_text_between_two_quoted_values_is_not_taken_for_a_value_with_parentheses(): void
    {
        $result = $this->sut->convert(
            $this->optimizer([
                'aggregator' => 'any',
                'conditions' => [$this->leaf('sku', '()', 'a'), $this->leaf('sku', '()', 'b')],
            ]),
            ['quick_search_container' => 0],
            null,
        );

        self::assertNull($result->skipReason);
    }

    public function test_sku_does_not_contain_becomes_is_not_one_of(): void
    {
        $result = $this->sut->convert(
            $this->optimizer(['conditions' => [$this->leaf('sku', '!{}', '10148-group')]]),
            ['quick_search_container' => 0],
            null,
        );

        self::assertSame('!()', $result->conditions['conditions'][0]['operator']);
    }

    public function test_any_aggregator_and_nested_combines_are_kept(): void
    {
        $result = $this->sut->convert(
            $this->optimizer([
                'aggregator' => 'any',
                'conditions' => [
                    $this->leaf('price', '>=', '20'),
                    ['type' => self::ES_COMBINE, 'aggregator' => 'all', 'value' => '1', 'conditions' => [
                        $this->leaf('category_ids', '()', '3'),
                        $this->leaf('trade_name', '==', 'Likör'),
                    ]],
                ],
            ]),
            ['catalog_view_container' => 0],
            null,
        );

        self::assertNull($result->skipReason);
        self::assertSame('any', $result->conditions['aggregator']);
        self::assertSame('all', $result->conditions['conditions'][1]['aggregator']);
        self::assertSame(Combine::class, $result->conditions['conditions'][1]['type']);
        self::assertFalse($result->applyToSearch);
        self::assertTrue($result->applyToCategory);
    }

    public function test_weight_is_the_rounded_constant_score_value(): void
    {
        $result = $this->sut->convert(
            $this->optimizer(['conditions' => [$this->leaf('sku', '()', 'a')]], '{"constant_score_value":"-12.6"}'),
            ['quick_search_container' => 0],
            null,
        );

        self::assertSame(-13, $result->weight);
    }

    public function test_autocomplete_container_counts_as_search(): void
    {
        $result = $this->sut->convert(
            $this->optimizer(['conditions' => [$this->leaf('sku', '()', 'a')]]),
            ['catalog_product_autocomplete' => 0],
            null,
        );

        self::assertTrue($result->applyToSearch);
    }

    public function test_query_limited_optimizer_is_converted_but_flagged_inactive(): void
    {
        $result = $this->sut->convert(
            $this->optimizer(['conditions' => [$this->leaf('sku', '()', 'a')]]),
            ['quick_search_container' => 1],
            ['query_count' => 3, 'queries' => ['birne', 'williams'], 'category_count' => 0],
        );

        self::assertNull($result->skipReason);
        self::assertSame(
            'limited to 3 search queries (birne, williams); boosting rules apply to every query',
            $result->inactiveReason,
        );
    }

    public function test_category_limited_optimizer_is_flagged_inactive(): void
    {
        $result = $this->sut->convert(
            $this->optimizer(['conditions' => [$this->leaf('sku', '()', 'a')]]),
            ['catalog_view_container' => 1],
            ['query_count' => 0, 'queries' => [], 'category_count' => 2],
        );

        self::assertSame(
            'limited to 2 categories; boosting rules apply to every category listing',
            $result->inactiveReason,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: array<string, int>, 3: string}>
     */
    public static function unconvertibleOptimizers(): array
    {
        $leaf = ['type' => self::ES_PRODUCT, 'attribute' => 'sku', 'operator' => '()', 'value' => 'a'];

        return [
            'attribute value model' => [
                ['model' => 'attribute_value', 'conditions' => [$leaf]],
                null,
                ['quick_search_container' => 0],
                'model "attribute_value" is not supported: boosting rules only have constant weights',
            ],
            'zero score' => [
                ['conditions' => [$leaf]],
                '{"constant_score_value":"0"}',
                ['quick_search_container' => 0],
                'a constant score of 0 has no effect',
            ],
            'no supported container' => [
                ['conditions' => [$leaf]],
                null,
                ['cms_search_container' => 0],
                'applies to no search or category listing container (cms_search_container)',
            ],
            'stock condition' => [
                ['conditions' => [['type' => self::ES_PRODUCT, 'attribute' => 'stock.is_in_stock', 'operator' => '==', 'value' => true]]],
                null,
                ['quick_search_container' => 0],
                'condition on "stock.is_in_stock" is not supported: boosting rules filter on name, sku, price, category_ids and the indexed Additional Attributes',
            ],
            'negated combine' => [
                ['value' => '0', 'conditions' => [$leaf]],
                null,
                ['quick_search_container' => 0],
                'a condition group with "is FALSE" is not supported',
            ],
            'empty conditions' => [
                ['conditions' => []],
                null,
                ['quick_search_container' => 0],
                'it has no conditions',
            ],
            'contains on a non-sku attribute' => [
                ['conditions' => [['type' => self::ES_PRODUCT, 'attribute' => 'trade_name', 'operator' => '{}', 'value' => 'Lik']]],
                null,
                ['quick_search_container' => 0],
                'operator "{}" on "trade_name" is not supported',
            ],
            'sku contains matching too many skus' => [
                ['conditions' => [['type' => self::ES_PRODUCT, 'attribute' => 'sku', 'operator' => '{}', 'value' => 'many']]],
                null,
                ['quick_search_container' => 0],
                'sku contains "many" matches too many SKUs',
            ],
            'sku contains with a truncated candidate list' => [
                ['conditions' => [['type' => self::ES_PRODUCT, 'attribute' => 'sku', 'operator' => '{}', 'value' => 'truncated']]],
                null,
                ['quick_search_container' => 0],
                'sku contains "truncated" matches too many SKUs',
            ],
            'sku contains matching no sku' => [
                ['conditions' => [['type' => self::ES_PRODUCT, 'attribute' => 'sku', 'operator' => '{}', 'value' => 'gone']]],
                null,
                ['quick_search_container' => 0],
                'sku contains "gone" matches no existing SKU',
            ],
            'parenthesis in a value' => [
                ['conditions' => [['type' => self::ES_PRODUCT, 'attribute' => 'sku', 'operator' => '()', 'value' => 'Birne (alt)']]],
                null,
                ['quick_search_container' => 0],
                'a condition value contains parentheses, which _eval cannot parse',
            ],
            'option attribute' => [
                ['conditions' => [['type' => self::ES_PRODUCT, 'attribute' => 'color', 'operator' => '==', 'value' => '12']]],
                null,
                ['quick_search_container' => 0],
                'condition on option attribute "color" is not supported: its values are option IDs, Typesense indexes labels',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $ruleOverrides
     * @param array<string, int> $containers
     */
    #[DataProvider('unconvertibleOptimizers')]
    public function test_unconvertible_optimizer_is_skipped_with_reason(
        array $ruleOverrides,
        ?string $config,
        array $containers,
        string $reason,
    ): void {
        $this->catalogLookup->method('isOptionAttribute')->willReturnCallback(
            static fn (string $code): bool => $code === 'color',
        );
        $model = $ruleOverrides['model'] ?? 'constant_score';
        unset($ruleOverrides['model']);
        $optimizer = $this->optimizer($ruleOverrides, $config ?? '{"constant_score_value":"1000"}');
        $optimizer['model'] = $model;

        $result = $this->sut->convert($optimizer, $containers, null);

        self::assertSame($reason, $result->skipReason);
    }

    public function test_invalid_rule_condition_json_is_skipped(): void
    {
        $optimizer = $this->optimizer([]);
        $optimizer['rule_condition'] = '{broken';

        $result = $this->sut->convert($optimizer, ['quick_search_container' => 0], null);

        self::assertSame('its rule condition is not valid JSON', $result->skipReason);
    }

    /**
     * @param array<string, mixed> $rootOverrides
     * @return array<string, mixed>
     */
    private function optimizer(array $rootOverrides, string $config = '{"constant_score_value":"1000"}'): array
    {
        $root = array_merge(
            [
                'type' => self::ES_COMBINE,
                'attribute' => null,
                'operator' => null,
                'value' => '1',
                'is_value_processed' => null,
                'aggregator' => 'all',
                'conditions' => [],
            ],
            $rootOverrides,
        );

        return [
            'optimizer_id' => 1,
            'store_id' => 3,
            'is_active' => true,
            'from_date' => null,
            'to_date' => null,
            'name' => 'Birnerla',
            'model' => 'constant_score',
            'config' => $config,
            'rule_condition' => json_encode($root, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaf(string $attribute, string $operator, mixed $value): array
    {
        return [
            'type' => self::ES_PRODUCT,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
            'is_value_processed' => false,
        ];
    }
}
