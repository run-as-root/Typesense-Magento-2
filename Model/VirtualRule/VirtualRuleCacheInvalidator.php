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
     * @return int[] IDs of every category whose cache was actually cleared: self + ancestors
     *     that had a rule row with a non-null cache, plus every category that mirrors any of
     *     those (via virtual_category_root_id), transitively (mirror-of-a-mirror), cycle-safe.
     */
    public function invalidate(int $categoryId, int $storeId): array
    {
        $cleared = [];

        foreach ([$categoryId, ...$this->getAncestorIds($categoryId)] as $id) {
            $this->clearRuleCache($id, $storeId, $cleared);
        }

        // Reverse-mirror cascade: anything that mirrors the changed category itself (even if its
        // own cache was already null by the time we got here — Save.php nulls it before calling
        // invalidate()) or any ancestor we just cleared must also be invalidated, and so on
        // transitively for mirror-of-a-mirror chains. Guard with a visited set: the resolver
        // already rejects true root cycles at save time, but the invalidator shouldn't assume the
        // data is always clean.
        $visited = [];
        $queue = [];

        foreach (array_unique([$categoryId, ...$cleared]) as $id) {
            $visited[$id] = true;
            $queue[] = $id;
        }

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ($this->ruleRepository->findRulesByVirtualCategoryRootId($id, $storeId) as $mirrorRule) {
                $mirrorCategoryId = $mirrorRule->getCategoryId();

                if (isset($visited[$mirrorCategoryId])) {
                    continue;
                }
                $visited[$mirrorCategoryId] = true;

                if ($mirrorRule->getCompiledFilterCache() !== null) {
                    $mirrorRule->setCompiledFilterCache(null);
                    $this->ruleRepository->save($mirrorRule);
                    $cleared[] = $mirrorCategoryId;
                }

                $queue[] = $mirrorCategoryId;
            }
        }

        return $cleared;
    }

    /**
     * @param int[] $cleared
     */
    private function clearRuleCache(int $categoryId, int $storeId, array &$cleared): void
    {
        $rule = $this->ruleRepository->findByCategoryAndStore($categoryId, $storeId);

        if ($rule !== null && $rule->getCompiledFilterCache() !== null) {
            $rule->setCompiledFilterCache(null);
            $this->ruleRepository->save($rule);
            $cleared[] = $categoryId;
        }
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
