<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;

interface BoostRuleRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): BoostRuleInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(BoostRuleInterface $entity): BoostRuleInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(BoostRuleInterface $entity): bool;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Active rules of the store view and of "All Store Views" (0) whose from/to window contains
     * $date (Y-m-d, both bounds inclusive, null bound = open), highest weight first.
     *
     * @return BoostRuleInterface[]
     */
    public function getActiveForStore(int $storeId, string $date): array;
}
