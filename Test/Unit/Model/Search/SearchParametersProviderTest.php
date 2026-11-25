<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Search;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Search\SearchParametersProvider;

final class SearchParametersProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private SearchParametersProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new SearchParametersProvider($this->config);
    }

    public function test_returns_production_defaults(): void
    {
        $this->configureDefaults(typoToleranceEnabled: true);

        self::assertSame(
            [
                'text_match_type' => 'max_weight',
                'drop_tokens_threshold' => 0,
                'typo_tokens_threshold' => 1,
                'num_typos' => 2,
                'min_len_1typo' => 5,
                'min_len_2typo' => 8,
                'prioritize_exact_match' => true,
                'prioritize_num_matching_fields' => true,
                'split_join_tokens' => 'fallback',
                'prefix' => true,
                'exhaustive_search' => false,
            ],
            $this->sut->getParameters(),
        );
    }

    public function test_exhaustive_search_follows_the_store_config(): void
    {
        $this->config->method('isExhaustiveSearchEnabled')->with(3)->willReturn(true);

        self::assertTrue($this->sut->getParameters(3)['exhaustive_search']);
    }

    public function test_typo_tolerance_off_sets_num_typos_zero(): void
    {
        $this->configureDefaults(typoToleranceEnabled: false);

        self::assertSame(0, $this->sut->getParameters()['num_typos']);
    }

    public function test_passes_store_id_to_config(): void
    {
        $this->config->expects(self::once())->method('getTextMatchType')->with(3)->willReturn('sum_score');
        $this->config->expects(self::once())->method('isTypoToleranceEnabled')->with(3)->willReturn(true);
        $this->config->method('getSplitJoinTokens')->willReturn('off');

        $parameters = $this->sut->getParameters(3);

        self::assertSame('sum_score', $parameters['text_match_type']);
        self::assertSame('off', $parameters['split_join_tokens']);
    }

    public function test_cache_parameters_empty_when_disabled(): void
    {
        $this->config->method('isSearchCacheEnabled')->willReturn(false);
        $this->config->expects(self::never())->method('getSearchCacheTtl');

        self::assertSame([], $this->sut->getCacheParameters());
    }

    public function test_cache_parameters_cast_ttl(): void
    {
        $this->config->method('isSearchCacheEnabled')->with(2)->willReturn(true);
        $this->config->method('getSearchCacheTtl')->with(2)->willReturn(300);

        self::assertSame(['use_cache' => true, 'cache_ttl' => 300], $this->sut->getCacheParameters(2));
    }

    private function configureDefaults(bool $typoToleranceEnabled): void
    {
        $this->config->method('getTextMatchType')->willReturn('max_weight');
        $this->config->method('getDropTokensThreshold')->willReturn(0);
        $this->config->method('getTypoTokensThreshold')->willReturn(1);
        $this->config->method('isTypoToleranceEnabled')->willReturn($typoToleranceEnabled);
        $this->config->method('getMinLen1Typo')->willReturn(5);
        $this->config->method('getMinLen2Typo')->willReturn(8);
        $this->config->method('isPrioritizeExactMatch')->willReturn(true);
        $this->config->method('getSplitJoinTokens')->willReturn('fallback');
        $this->config->method('isPrefixSearchEnabled')->willReturn(true);
    }
}
