<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;

interface CategoryVirtualRuleRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): CategoryVirtualRuleInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(CategoryVirtualRuleInterface $entity): CategoryVirtualRuleInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(CategoryVirtualRuleInterface $entity): bool;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Convenience lookup used by the resolver and cache invalidator.
     */
    public function findByCategoryAndStore(int $categoryId, int $storeId): ?CategoryVirtualRuleInterface;

    /**
     * Reverse lookup for VirtualRuleCacheInvalidator: every rule row at this store whose
     * virtual_category_root_id points at the given category (i.e. every category that mirrors
     * $rootCategoryId), so a change to $rootCategoryId can cascade to its mirrors.
     *
     * @return CategoryVirtualRuleInterface[]
     */
    public function findRulesByVirtualCategoryRootId(int $rootCategoryId, int $storeId): array;
}
