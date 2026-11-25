<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Combine;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

/**
 * Converts an ElasticSuite optimizer into boosting rule data, or explains why it cannot be. Only what has an
 * exact equivalent is converted; anything else is skipped rather than approximated.
 */
class OptimizerConverter
{
    public const string CONTAINER_QUICK_SEARCH = 'quick_search_container';
    public const string CONTAINER_AUTOCOMPLETE = 'catalog_product_autocomplete';
    public const string CONTAINER_CATEGORY = 'catalog_view_container';

    private const string MODEL_CONSTANT_SCORE = 'constant_score';
    private const array PLAIN_OPERATORS = ['==', '!=', '>=', '<=', '>', '<', '()', '!()'];
    // ElasticSuite's "contains" on sku is a full-text match of the value's words, which Typesense filters cannot
    // express; it becomes "is one of" the SKUs containing the value as whole words today (10029-group also
    // matches 10029-group-023), and the expansion is reported.
    private const array SKU_CONTAINS_OPERATORS = ['{}' => '()', '!{}' => '!()'];
    private const int MAX_EXPANDED_SKUS = 20;

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly CatalogLookup $catalogLookup,
        private readonly TypeSenseConfigInterface $config,
        private readonly ConditionsToFilterByCompiler $compiler,
    ) {
    }

    /**
     * @param array<string, mixed> $optimizer a smile_elasticsuite_optimizer row
     * @param array<string, int> $containers apply_to by search container name
     * @param array{query_count: int, queries: list<string>, category_count: int}|null $limitation
     */
    public function convert(array $optimizer, array $containers, ?array $limitation): OptimizerConversion
    {
        $model = (string) ($optimizer['model'] ?? '');
        if ($model !== self::MODEL_CONSTANT_SCORE) {
            return OptimizerConversion::skipped(
                sprintf('model "%s" is not supported: boosting rules only have constant weights', $model),
            );
        }

        $weight = $this->getWeight((string) ($optimizer['config'] ?? ''));
        if ($weight === 0) {
            return OptimizerConversion::skipped('a constant score of 0 has no effect');
        }

        $applyToSearch = isset($containers[self::CONTAINER_QUICK_SEARCH])
            || isset($containers[self::CONTAINER_AUTOCOMPLETE]);
        $applyToCategory = isset($containers[self::CONTAINER_CATEGORY]);
        if (!$applyToSearch && !$applyToCategory) {
            return OptimizerConversion::skipped(sprintf(
                'applies to no search or category listing container (%s)',
                implode(', ', array_keys($containers)) ?: 'none',
            ));
        }

        $tree = json_decode((string) ($optimizer['rule_condition'] ?? ''), true);
        if (!is_array($tree)) {
            return OptimizerConversion::skipped('its rule condition is not valid JSON');
        }

        $storeId = (int) ($optimizer['store_id'] ?? 0);
        $this->notes = [];
        try {
            $conditions = $this->convertNode($tree, $storeId);
            $filter = $this->compiler->compile($conditions);
        } catch (UnsupportedConditionException $e) {
            return OptimizerConversion::skipped($e->getMessage());
        } catch (\Throwable $e) {
            return OptimizerConversion::skipped('its conditions do not compile: ' . $e->getMessage());
        }

        // BoostSortBuilder drops such rules at runtime: Typesense 29's _eval parser cannot read them. Quoted values
        // are matched as consecutive pairs, so the text between two values is never taken for one.
        preg_match_all('/`[^`]*`/', $filter, $matches);
        $quotedValues = $matches[0];
        foreach ($quotedValues as $quotedValue) {
            if (strpbrk($quotedValue, '()') !== false) {
                return OptimizerConversion::skipped('a condition value contains parentheses, which _eval cannot parse');
            }
        }

        return OptimizerConversion::converted(
            $weight,
            $applyToSearch,
            $applyToCategory,
            $conditions,
            $this->describeLimitation($containers, $limitation),
            $this->notes,
        );
    }

    /**
     * ElasticSuite multiplies the score of matching products by 1 + value/100; boosting rules rank by weight within
     * a text-match bucket. Keeping the value as the weight keeps the order between rules (monotonic).
     */
    private function getWeight(string $config): int
    {
        $decoded = json_decode($config, true);
        $value = is_array($decoded) ? ($decoded['constant_score_value'] ?? null) : null;

        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    /**
     * @param array<string, int> $containers
     * @param array{query_count: int, queries: list<string>, category_count: int}|null $limitation
     */
    private function describeLimitation(array $containers, ?array $limitation): ?string
    {
        if ($limitation === null) {
            return null;
        }

        $reasons = [];
        if (($containers[self::CONTAINER_QUICK_SEARCH] ?? 0) !== 0 && $limitation['query_count'] > 0) {
            $reasons[] = sprintf(
                'limited to %d search queries (%s); boosting rules apply to every query',
                $limitation['query_count'],
                $limitation['queries'] === [] ? 'no longer in search_query' : implode(', ', $limitation['queries']),
            );
        }

        $categoryApplyTo = $containers[self::CONTAINER_CATEGORY] ?? 0;
        if ($categoryApplyTo !== 0 && $limitation['category_count'] > 0) {
            $reasons[] = sprintf(
                '%s %d categories; boosting rules apply to every category listing',
                $categoryApplyTo === 2 ? 'excludes' : 'limited to',
                $limitation['category_count'],
            );
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     * @throws UnsupportedConditionException
     */
    private function convertNode(array $node, int $storeId): array
    {
        if (!array_key_exists('conditions', $node)) {
            return $this->convertLeaf($node, $storeId);
        }

        if (!in_array($node['value'] ?? '1', ['1', 1, true], true)) {
            throw new UnsupportedConditionException('a condition group with "is FALSE" is not supported');
        }

        $children = is_array($node['conditions']) ? $node['conditions'] : [];
        if ($children === []) {
            throw new UnsupportedConditionException('it has no conditions');
        }

        $converted = [];
        foreach ($children as $child) {
            $converted[] = $this->convertNode(is_array($child) ? $child : [], $storeId);
        }

        return [
            'type' => Combine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'is_value_processed' => null,
            'aggregator' => ($node['aggregator'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions' => $converted,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     * @throws UnsupportedConditionException
     */
    private function convertLeaf(array $node, int $storeId): array
    {
        $attribute = (string) ($node['attribute'] ?? '');
        $operator = (string) ($node['operator'] ?? '');
        $value = $node['value'] ?? null;

        $allowed = array_merge(Product::CORE_FILTERABLE_ATTRIBUTES, $this->config->getAdditionalAttributes($storeId));
        if (!in_array($attribute, $allowed, true)) {
            throw new UnsupportedConditionException(sprintf(
                'condition on "%s" is not supported: boosting rules filter on name, sku, price, category_ids'
                . ' and the indexed Additional Attributes',
                $attribute,
            ));
        }

        if (!in_array($attribute, Product::CORE_FILTERABLE_ATTRIBUTES, true)
            && $this->catalogLookup->isOptionAttribute($attribute)
        ) {
            throw new UnsupportedConditionException(sprintf(
                'condition on option attribute "%s" is not supported: its values are option IDs, Typesense indexes labels',
                $attribute,
            ));
        }

        if ($attribute === 'sku' && isset(self::SKU_CONTAINS_OPERATORS[$operator])) {
            $value = $this->expandSkus($value, $operator);
            $operator = self::SKU_CONTAINS_OPERATORS[$operator];
        }

        if (!in_array($operator, self::PLAIN_OPERATORS, true)) {
            throw new UnsupportedConditionException(
                sprintf('operator "%s" on "%s" is not supported', $operator, $attribute),
            );
        }

        return [
            'type' => Product::class,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
            'is_value_processed' => false,
        ];
    }

    /**
     * @throws UnsupportedConditionException
     */
    private function expandSkus(mixed $value, string $operator): string
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $expanded = [];

        foreach ($values as $sku) {
            $sku = trim((string) $sku);
            $wordPattern = '/(^|[^[:alnum:]])' . preg_quote($sku, '/') . '([^[:alnum:]]|$)/iu';
            $candidates = $this->catalogLookup->findSkusContaining($sku);
            // A truncated candidate list could miss whole-word matches.
            if (count($candidates) >= CatalogLookup::SKU_LIMIT) {
                throw new UnsupportedConditionException(sprintf('sku contains "%s" matches too many SKUs', $sku));
            }
            $matches = array_values(array_filter(
                $candidates,
                static fn (string $candidate): bool => preg_match($wordPattern, $candidate) === 1,
            ));

            if ($matches === []) {
                throw new UnsupportedConditionException(sprintf('sku contains "%s" matches no existing SKU', $sku));
            }
            if (count($matches) > self::MAX_EXPANDED_SKUS) {
                throw new UnsupportedConditionException(sprintf('sku contains "%s" matches too many SKUs', $sku));
            }
            if ($matches !== [$sku]) {
                $this->notes[] = sprintf(
                    'sku %s "%s" became %s %s (the SKUs containing it today)',
                    $operator === '{}' ? 'contains' : 'does not contain',
                    $sku,
                    $operator === '{}' ? 'is one of' : 'is not one of',
                    implode(', ', $matches),
                );
            }
            $expanded[] = $matches;
        }

        return implode(', ', array_values(array_unique(array_merge(...$expanded))));
    }
}
