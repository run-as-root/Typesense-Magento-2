<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

/**
 * Turns ElasticSuite's positions of one category into Magento category positions with the same listing order:
 * ElasticSuite lists positioned products first, in position order, and the rest after them. Positioned products
 * keep their ElasticSuite position; assigned products without one move behind the highest position, in their
 * Magento order. A product an anchor category only inherits from a descendant is assigned to the category, as
 * Magento can only position assigned products.
 */
class CategoryPositionPlanner
{
    private const string REASON_NO_POSITION = 'no position (ElasticSuite sorted it among the unpositioned products)';
    private const string REASON_NOT_LISTED = 'not listed in the category (neither assigned nor inherited by an anchor)';

    /**
     * @param array<int, int|null> $esPositions ElasticSuite position by product id (null or 0: no position)
     * @param array<int, int> $assigned current Magento position by assigned product id
     * @param list<int> $inherited product ids an anchor category lists through a descendant
     * @return array{
     *     positions: array<int, int>,
     *     created: list<int>,
     *     updated: list<int>,
     *     unchanged: list<int>,
     *     moved: list<int>,
     *     skipped: list<array{product: int, reason: string}>,
     * }
     */
    public function plan(array $esPositions, array $assigned, array $inherited): array
    {
        $plan = ['positions' => [], 'created' => [], 'updated' => [], 'unchanged' => [], 'moved' => [], 'skipped' => []];
        $positioned = [];

        foreach ($esPositions as $productId => $position) {
            if ($position === null || $position === 0) {
                $plan['skipped'][] = ['product' => $productId, 'reason' => self::REASON_NO_POSITION];
                continue;
            }
            if (!isset($assigned[$productId]) && !in_array($productId, $inherited, true)) {
                $plan['skipped'][] = ['product' => $productId, 'reason' => self::REASON_NOT_LISTED];
                continue;
            }
            $positioned[$productId] = $position;
        }

        if ($positioned === []) {
            return $plan;
        }

        foreach ($positioned as $productId => $position) {
            if (!isset($assigned[$productId])) {
                $plan['positions'][$productId] = $position;
                $plan['created'][] = $productId;
            } elseif ($assigned[$productId] !== $position) {
                $plan['positions'][$productId] = $position;
                $plan['updated'][] = $productId;
            } else {
                $plan['unchanged'][] = $productId;
            }
        }

        $others = array_diff_key($assigned, $positioned);
        $highest = max($positioned);
        if ($others === [] || min($others) > $highest) {
            return $plan;
        }

        $order = array_keys($others);
        usort(
            $order,
            static fn (int $a, int $b): int => [$others[$a], $a] <=> [$others[$b], $b],
        );
        foreach ($order as $rank => $productId) {
            $position = $highest + $rank + 1;
            if ($others[$productId] !== $position) {
                $plan['positions'][$productId] = $position;
                $plan['moved'][] = $productId;
            }
        }

        return $plan;
    }
}
