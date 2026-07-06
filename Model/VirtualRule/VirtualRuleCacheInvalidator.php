<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;

class VirtualRuleCacheInvalidator
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryVirtualRuleRepositoryInterface $ruleRepository,
    ) {
    }

    /**
     * @return int[] IDs of every category whose cache was actually cleared
     *     (self + ancestors that had a rule row with a non-null cache).
     */
    public function invalidate(int $categoryId, int $storeId): array
    {
        $cleared = [];

        foreach ([$categoryId, ...$this->getAncestorIds($categoryId)] as $id) {
            $rule = $this->ruleRepository->findByCategoryAndStore($id, $storeId);

            if ($rule !== null && $rule->getCompiledFilterCache() !== null) {
                $rule->setCompiledFilterCache(null);
                $this->ruleRepository->save($rule);
                $cleared[] = $id;
            }
        }

        return $cleared;
    }

    /**
     * @return int[]
     */
    private function getAncestorIds(int $categoryId): array
    {
        $category = $this->categoryRepository->get($categoryId);
        $pathIds = array_map('intval', explode('/', (string) $category->getPath()));

        // Drop the category itself (last path segment) and the root/store-root placeholders (id <= 1)
        array_pop($pathIds);

        return array_values(array_filter($pathIds, fn(int $id): bool => $id > 1));
    }
}
