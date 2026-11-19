<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Search;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Search\SearchableAttributesProvider;
use RunAsRoot\TypeSense\Model\Search\SearchParametersProvider;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;

final class SearchRequestParamsBuilderTest extends TestCase
{
    private SearchParametersProvider&MockObject $searchParametersProvider;
    private SearchableAttributesProvider&MockObject $searchableAttributesProvider;
    private SearchRequestParamsBuilder $sut;

    protected function setUp(): void
    {
        $this->searchParametersProvider = $this->createMock(SearchParametersProvider::class);
        $this->searchableAttributesProvider = $this->createMock(SearchableAttributesProvider::class);
        $this->searchableAttributesProvider->method('getWeightedFields')
            ->willReturn(['name' => 10, 'sku' => 9, 'description' => 1]);
        $this->sut = new SearchRequestParamsBuilder(
            $this->searchParametersProvider,
            $this->searchableAttributesProvider,
        );
    }

    public function test_build_merges_search_parameters_and_query_by(): void
    {
        $this->searchParametersProvider->method('getParameters')
            ->with(4)
            ->willReturn(['text_match_type' => 'max_weight', 'num_typos' => 2, 'prefix' => true]);

        self::assertSame(
            [
                'text_match_type' => 'max_weight',
                'num_typos' => 2,
                'prefix' => true,
                'query_by' => 'name,sku,description',
                'query_by_weights' => '10,9,1',
            ],
            $this->sut->build(SearchRequestParamsBuilder::CONTEXT_SEARCH, 4),
        );
    }

    public function test_builder_query_by_and_weights_are_aligned(): void
    {
        $searchableAttributesProvider = $this->createMock(SearchableAttributesProvider::class);
        $searchableAttributesProvider->expects(self::once())->method('getWeightedFields')->with(2)
            ->willReturn(['sku' => 9, 'name' => 5]);
        $this->searchParametersProvider->method('getParameters')->willReturn([]);
        $sut = new SearchRequestParamsBuilder($this->searchParametersProvider, $searchableAttributesProvider);

        self::assertSame(
            ['query_by' => 'sku,name', 'query_by_weights' => '9,5'],
            $sut->build(SearchRequestParamsBuilder::CONTEXT_SEARCH, 2),
        );
    }

    public function test_build_returns_same_parameters_for_every_context(): void
    {
        $this->searchParametersProvider->method('getParameters')->willReturn(['num_typos' => 0]);

        $search = $this->sut->build(SearchRequestParamsBuilder::CONTEXT_SEARCH);

        self::assertSame($search, $this->sut->build(SearchRequestParamsBuilder::CONTEXT_AUTOCOMPLETE));
        self::assertSame($search, $this->sut->build(SearchRequestParamsBuilder::CONTEXT_CATEGORY));
    }

    public function test_relevance_sort_by_is_null_without_boost_rules(): void
    {
        self::assertNull($this->sut->getRelevanceSortBy(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1));
        self::assertNull($this->sut->getRelevanceSortBy(SearchRequestParamsBuilder::CONTEXT_CATEGORY));
    }
}
