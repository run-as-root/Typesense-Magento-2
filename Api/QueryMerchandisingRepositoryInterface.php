<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\QueryMerchandisingInterface;

interface QueryMerchandisingRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): QueryMerchandisingInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(QueryMerchandisingInterface $entity): QueryMerchandisingInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(QueryMerchandisingInterface $entity): bool;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
