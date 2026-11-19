<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

/**
 * Single source of the category listing base filter: Typesense only applies a merchandising
 * override whose rule.filter_by matches the search filter_by exactly, so the storefront and
 * CategoryMerchandisingSync must both use this string.
 */
class CategoryListingFilterBuilder
{
    private const VISIBILITY_CLAUSE = 'visibility:[2,4]';

    public function __construct(
        private readonly CategoryVirtualRuleResolver $virtualRuleResolver,
    ) {
    }

    public function build(int $categoryId, int $storeId): string
    {
        // Parenthesised because virtual category filters can be top-level "||" chains.
        return sprintf(
            '(%s) && %s',
            $this->virtualRuleResolver->resolveFilter($categoryId, $storeId),
            self::VISIBILITY_CLAUSE,
        );
    }
}
