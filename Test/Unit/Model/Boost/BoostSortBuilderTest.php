<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Boost;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;
use RunAsRoot\TypeSense\Model\Boost\BoostSortBuilder;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;
use RunAsRoot\TypeSense\Model\VirtualRule\AllowedAttributeValidator;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

final class BoostSortBuilderTest extends TestCase
{
    private BoostRuleRepositoryInterface&MockObject $repository;
    private ConditionsToFilterByCompiler&MockObject $compiler;
    private LoggerInterface&MockObject $logger;
    private TypeSenseConfigInterface&MockObject $config;
    private AllowedAttributeValidator $validator;
    private BoostSortBuilder $sut;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(BoostRuleRepositoryInterface::class);
        $this->compiler = $this->createMock(ConditionsToFilterByCompiler::class);
        $this->compiler->method('compile')->willReturnCallback(
            static fn(array $conditions): string => $conditions['filter'] ?? throw new LocalizedException(
                __('Virtual category condition is missing an attribute or value.'),
            ),
        );
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('scopeDate')->willReturn(new \DateTime('2026-12-24 23:30:00'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->validator = new AllowedAttributeValidator($this->config);

        $this->sut = new BoostSortBuilder(
            $this->repository,
            $this->compiler,
            $timezone,
            $this->logger,
            $this->validator,
        );
    }

    public function test_eval_clause_combines_rules_with_weights(): void
    {
        $this->repository->method('getActiveForStore')->willReturn([
            $this->rule(1, 1000, 'F1'),
            $this->rule(2, 100, 'F2'),
            $this->rule(3, -50, 'F3'),
        ]);

        self::assertSame(
            '_eval([(F1):1000, (F2):100, (F3):-50])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1),
        );
    }

    public function test_rule_that_fails_to_compile_is_skipped_and_logged(): void
    {
        $this->repository->method('getActiveForStore')->willReturn([
            $this->rule(7, 500, null),
            $this->rule(8, 400, ConditionsToFilterByCompiler::NO_MATCH_FILTER),
            $this->rule(9, 100, 'F2'),
        ]);

        $loggedRuleIds = [];
        $this->logger->expects(self::exactly(2))
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context) use (&$loggedRuleIds): void {
                $loggedRuleIds[] = $context['rule_id'];
            });

        self::assertSame(
            '_eval([(F2):100])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1),
        );
        self::assertSame([7, 8], $loggedRuleIds);
    }

    public function test_rule_with_parenthesis_in_quoted_value_is_skipped_and_logged(): void
    {
        $this->repository->method('getActiveForStore')->willReturn([
            $this->rule(4, 500, 'name:=`Birne (alt`'),
            $this->rule(5, 300, '(sku:=`a)`) || (price:>5)'),
            $this->rule(6, 100, '(price:>5) && (in_stock:=true)'),
        ]);

        $loggedRuleIds = [];
        $this->logger->expects(self::exactly(2))
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context) use (&$loggedRuleIds): void {
                self::assertStringContainsString('parenthes', $message);
                $loggedRuleIds[] = $context['rule_id'];
            });

        self::assertSame(
            '_eval([((price:>5) && (in_stock:=true)):100])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1),
        );
        self::assertSame([4, 5], $loggedRuleIds);
    }

    public function test_rule_with_commas_and_colons_in_quoted_values_is_kept(): void
    {
        $this->repository->method('getActiveForStore')->willReturn([
            $this->rule(4, 500, 'sku:[`x, y`,`10029-group`] && name:=`Birne: 1,5l`'),
            $this->rule(5, 100, '(name:=`x`) && (sku:=`y`)'),
        ]);
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(
            '_eval([(sku:[`x, y`,`10029-group`] && name:=`Birne: 1,5l`):500, ((name:=`x`) && (sku:=`y`)):100])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1),
        );
    }

    public function test_rule_on_attribute_not_indexed_for_the_store_is_skipped_and_logged(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturnMap([
            [2, ['manufacturer']],
        ]);
        $this->repository->method('getActiveForStore')->willReturn([
            $this->rule(11, 50, 'manufacturer:=`Prinz`', attributes: ['manufacturer', 'color']),
            $this->rule(12, 20, 'sku:=`x`', attributes: ['sku']),
        ]);

        $logged = [];
        $this->logger->expects(self::once())
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
                $logged[] = [$message, $context];
            });

        self::assertSame(
            '_eval([(sku:=`x`):20])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 2),
        );
        self::assertStringContainsString('Boosting rule 11 skipped', $logged[0][0]);
        self::assertStringContainsString('color', $logged[0][0]);
        self::assertSame(11, $logged[0][1]['rule_id']);
        self::assertSame(['color'], $logged[0][1]['attributes']);
    }

    public function test_rule_on_attributes_indexed_for_the_store_is_kept(): void
    {
        $this->config->expects(self::atLeastOnce())
            ->method('getAdditionalAttributes')
            ->with(3)
            ->willReturn(['manufacturer']);
        $this->repository->method('getActiveForStore')->willReturn([
            $this->rule(11, 50, 'manufacturer:=`Prinz` && price:>5', attributes: ['manufacturer', 'price']),
        ]);
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(
            '_eval([(manufacturer:=`Prinz` && price:>5):50])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 3),
        );
    }

    public function test_returns_null_when_no_rule_is_usable(): void
    {
        $this->repository->method('getActiveForStore')->willReturn([$this->rule(7, 500, null)]);

        self::assertNull($this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1));
    }

    public function test_rules_respect_context_flags(): void
    {
        $this->repository->method('getActiveForStore')->willReturn([
            $this->rule(1, 300, 'SEARCH_ONLY', applyToSearch: true, applyToCategory: false),
            $this->rule(2, 200, 'CATEGORY_ONLY', applyToSearch: false, applyToCategory: true),
            $this->rule(3, 100, 'BOTH', applyToSearch: true, applyToCategory: true),
        ]);

        self::assertSame(
            '_eval([(SEARCH_ONLY):300, (BOTH):100])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1),
        );
        self::assertSame(
            '_eval([(SEARCH_ONLY):300, (BOTH):100])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_AUTOCOMPLETE, 1),
        );
        self::assertSame(
            '_eval([(CATEGORY_ONLY):200, (BOTH):100])',
            $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_CATEGORY, 1),
        );
    }

    public function test_out_of_window_inactive_and_foreign_store_rules_are_excluded(): void
    {
        // Active/window/store filtering is the repository's contract; the builder must ask it for
        // the requested store and the store-local date, not the server date.
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->expects(self::once())->method('scopeDate')->with(3)
            ->willReturn(new \DateTime('2026-12-24 23:30:00'));
        $this->repository->expects(self::once())
            ->method('getActiveForStore')
            ->with(3, '2026-12-24')
            ->willReturn([$this->rule(1, 10, 'F1')]);
        $sut = new BoostSortBuilder($this->repository, $this->compiler, $timezone, $this->logger, $this->validator);

        self::assertSame('_eval([(F1):10])', $sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 3));
    }

    public function test_clause_is_memoised_per_context_and_store(): void
    {
        $this->repository->expects(self::exactly(2))
            ->method('getActiveForStore')
            ->willReturn([$this->rule(1, 10, 'F1')]);

        $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1);
        $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 1);
        $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 2);
        $this->sut->buildEvalClause(SearchRequestParamsBuilder::CONTEXT_SEARCH, 2);
    }

    private function rule(
        int $id,
        int $weight,
        ?string $filter,
        bool $applyToSearch = true,
        bool $applyToCategory = true,
        array $attributes = [],
    ): BoostRuleInterface&MockObject {
        $rule = $this->createMock(BoostRuleInterface::class);
        $rule->method('getId')->willReturn($id);
        $rule->method('getWeight')->willReturn($weight);
        $rule->method('isApplyToSearch')->willReturn($applyToSearch);
        $rule->method('isApplyToCategory')->willReturn($applyToCategory);
        $leaves = array_map(static fn(string $code): array => ['attribute' => $code], $attributes);
        $rule->method('getConditions')->willReturn(
            $filter === null ? ['conditions' => $leaves] : ['filter' => $filter, 'conditions' => $leaves],
        );

        return $rule;
    }
}
