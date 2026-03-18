<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\LandingPageInterface;

interface LandingPageRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): LandingPageInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(LandingPageInterface $entity): LandingPageInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(LandingPageInterface $entity): bool;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
