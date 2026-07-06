<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;

class CategoryVirtualRuleResolver
{
    public function __construct(
        private readonly CategoryVirtualRuleRepositoryInterface $ruleRepository,
        private readonly ConditionsToFilterByCompiler $compiler,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly Json $json,
    ) {
    }

    public function resolveFilter(int $categoryId, int $storeId): string
    {
        return $this->resolve($categoryId, $storeId, []);
    }

    /**
     * @param array<int, bool> $visitedPath
     */
    private function resolve(int $categoryId, int $storeId, array $visitedPath): string
    {
        if (isset($visitedPath[$categoryId])) {
            throw new LocalizedException(__(
                'Circular virtual category root reference detected at category %1.',
                $categoryId,
            ));
        }
        $visitedPath[$categoryId] = true;

        $rule = $this->ruleRepository->findByCategoryAndStore($categoryId, $storeId);

        if ($rule === null || !$rule->isVirtual()) {
            return $this->staticFilter($categoryId);
        }

        $cached = $rule->getCompiledFilterCache();
        if ($cached !== null) {
            return $cached;
        }

        $effective = $this->compileEffectiveFilter($rule, $storeId, $visitedPath);

        $rule->setCompiledFilterCache($effective);
        $this->ruleRepository->save($rule);

        return $effective;
    }

    /**
     * @param array<int, bool> $visitedPath
     */
    private function compileEffectiveFilter(
        CategoryVirtualRuleInterface $rule,
        int $storeId,
        array $visitedPath,
    ): string {
        $ownFilter = $this->compiler->compile($this->decodeConditions($rule));

        $rootId = $rule->getVirtualCategoryRootId();
        if ($rootId !== null) {
            $rootFilter = $this->resolve($rootId, $storeId, $visitedPath);
            $ownFilter = "({$rootFilter}) && ({$ownFilter})";
        }

        $childIds = $this->getDirectChildIds($rule->getCategoryId());
        if ($childIds === []) {
            return $ownFilter;
        }

        $childFilters = array_map(
            fn(int $childId): string => '(' . $this->resolve($childId, $storeId, $visitedPath) . ')',
            $childIds,
        );

        return "({$ownFilter}) || " . implode(' || ', $childFilters);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConditions(CategoryVirtualRuleInterface $rule): array
    {
        $serialized = $rule->getConditionsSerialized();
        if ($serialized === null || $serialized === '') {
            return ['aggregator' => 'all', 'conditions' => []];
        }

        return $this->json->unserialize($serialized);
    }

    /**
     * @return int[]
     */
    private function getDirectChildIds(int $categoryId): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToFilter('is_active', 1);
        $collection->addFieldToFilter('parent_id', $categoryId);

        return array_map('intval', $collection->getAllIds());
    }

    private function staticFilter(int $categoryId): string
    {
        return "category_ids:={$categoryId}";
    }
}
