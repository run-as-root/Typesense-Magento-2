<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;

interface CategoryMerchandisingRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): CategoryMerchandisingInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(CategoryMerchandisingInterface $entity): CategoryMerchandisingInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(CategoryMerchandisingInterface $entity): bool;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
