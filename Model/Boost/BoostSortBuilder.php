<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Boost;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

class BoostSortBuilder
{
    /** @var array<string, string|null> keyed by "<context>|<storeId>" */
    private array $clauses = [];

    public function __construct(
        private readonly BoostRuleRepositoryInterface $ruleRepository,
        private readonly ConditionsToFilterByCompiler $compiler,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * `_eval([(<filter>):<weight>, …])` of the store's active rules for the context, or null when
     * no rule applies. Memoised because autocomplete and the listing ViewModels share one request.
     */
    public function buildEvalClause(string $context, int $storeId): ?string
    {
        $key = $context . '|' . $storeId;

        if (!array_key_exists($key, $this->clauses)) {
            $this->clauses[$key] = $this->compileClause($context, $storeId);
        }

        return $this->clauses[$key];
    }

    private function compileClause(string $context, int $storeId): ?string
    {
        // Rule windows are store-local calendar days, so "today" must be the store's, not the server's.
        $today = $this->timezone->scopeDate($storeId)->format('Y-m-d');
        $rules = $this->ruleRepository->getActiveForStore($storeId, $today);

        $terms = [];
        foreach ($rules as $rule) {
            if (!$this->appliesTo($rule, $context)) {
                continue;
            }

            $filter = $this->compileFilter($rule);
            if ($filter !== null) {
                $terms[] = '(' . $filter . '):' . $rule->getWeight();
            }
        }

        return $terms === [] ? null : '_eval([' . implode(', ', $terms) . '])';
    }

    private function appliesTo(BoostRuleInterface $rule, string $context): bool
    {
        return match ($context) {
            SearchRequestParamsBuilder::CONTEXT_SEARCH,
            SearchRequestParamsBuilder::CONTEXT_AUTOCOMPLETE => $rule->isApplyToSearch(),
            SearchRequestParamsBuilder::CONTEXT_CATEGORY => $rule->isApplyToCategory(),
            default => false,
        };
    }

    /**
     * One broken rule must not break the storefront sort, so it is dropped with a warning.
     */
    private function compileFilter(BoostRuleInterface $rule): ?string
    {
        try {
            $filter = $this->compiler->compile($rule->getConditions());
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Boosting rule %d skipped: its conditions do not compile - %s',
                    $rule->getId(),
                    $e->getMessage(),
                ),
                ['rule_id' => $rule->getId(), 'exception' => $e],
            );

            return null;
        }

        // Typesense 29's _eval list parser splits on parentheses even inside backtick-quoted values
        // (`Birne (alt` makes the whole sort_by malformed, breaking every search), so such rules are
        // dropped rather than risking the store's search.
        if ($this->hasParenthesisInQuotedValue($filter)) {
            $this->logger->warning(
                sprintf('Boosting rule %d skipped: a condition value contains parentheses', $rule->getId()),
                ['rule_id' => $rule->getId()],
            );

            return null;
        }

        if ($filter === ConditionsToFilterByCompiler::NO_MATCH_FILTER) {
            $this->logger->warning(
                sprintf('Boosting rule %d skipped: it has no conditions', $rule->getId()),
                ['rule_id' => $rule->getId()],
            );

            return null;
        }

        return $filter;
    }

    private function hasParenthesisInQuotedValue(string $filter): bool
    {
        // Matched as consecutive pairs (the compiler rejects backticks inside values), so the
        // unquoted text between two values is never mistaken for a value.
        preg_match_all('/`[^`]*`/', $filter, $matches);
        $quotedValues = $matches[0];

        foreach ($quotedValues as $quotedValue) {
            if (strpbrk($quotedValue, '()') !== false) {
                return true;
            }
        }

        return false;
    }
}
