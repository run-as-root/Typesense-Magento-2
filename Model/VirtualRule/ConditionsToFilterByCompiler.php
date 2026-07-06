<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\Exception\LocalizedException;

class ConditionsToFilterByCompiler
{
    public const NO_MATCH_FILTER = 'id:=__typesense_virtual_category_no_match__';

    private const CHILDREN_KEY = 'conditions';

    private const OPERATOR_MAP = [
        '==' => ':=',
        '!=' => ':!=',
        '>=' => ':>=',
        '<=' => ':<=',
        '>'  => ':>',
        '<'  => ':<',
    ];

    /**
     * @param array<string, mixed> $node A combine node (has a "conditions" array) or a leaf
     *     node (has "attribute"/"operator"/"value"), matching Magento\Rule's own
     *     getConditions()->asArray() shape.
     */
    public function compile(array $node): string
    {
        if (array_key_exists(self::CHILDREN_KEY, $node)) {
            return $this->compileCombine($node);
        }

        return $this->compileLeaf($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function compileCombine(array $node): string
    {
        $children = $node[self::CHILDREN_KEY] ?? [];

        if ($children === []) {
            return self::NO_MATCH_FILTER;
        }

        $aggregator = ($node['aggregator'] ?? 'all') === 'any' ? '||' : '&&';

        $parts = array_map(
            fn(array $child): string => '(' . $this->compile($child) . ')',
            $children,
        );

        return implode(" {$aggregator} ", $parts);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function compileLeaf(array $node): string
    {
        $attribute = (string) ($node['attribute'] ?? '');
        $operator = (string) ($node['operator'] ?? '==');
        $value = $node['value'] ?? null;

        // Deliberately checks null/'' only — NOT empty()/truthiness — so that
        // false, 0, and "0" condition values are preserved.
        if ($attribute === '' || $value === null || $value === '') {
            throw new LocalizedException(__('Virtual category condition is missing an attribute or value.'));
        }

        if (in_array($operator, ['()', '!()'], true)) {
            return $this->compileInOperator($attribute, $operator, $value);
        }

        if (!isset(self::OPERATOR_MAP[$operator])) {
            throw new LocalizedException(__('Unsupported virtual category operator "%1".', $operator));
        }

        if (!is_scalar($value)) {
            throw new LocalizedException(
                __('Virtual category condition value must be scalar for operator "%1".', $operator),
            );
        }

        return $attribute . self::OPERATOR_MAP[$operator] . $this->formatValue($value);
    }

    private function compileInOperator(string $attribute, string $operator, mixed $value): string
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $formatted = implode(',', array_map(
            fn($v): string => $this->formatValue(trim((string) $v)),
            $values,
        ));
        $prefix = $operator === '!()' ? ':!=' : ':';

        return "{$attribute}{$prefix}[{$formatted}]";
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $stringValue = (string) $value;

        if ($stringValue === 'true' || $stringValue === 'false') {
            return $stringValue;
        }

        if (str_contains($stringValue, '`')) {
            throw new LocalizedException(
                __('Virtual category condition values may not contain a backtick character.'),
            );
        }

        return '`' . $stringValue . '`';
    }
}
