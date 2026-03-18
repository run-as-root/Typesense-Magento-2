<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Merchandising;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising as CategoryMerchandisingResource;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising\CollectionFactory;

class CategoryMerchandisingRepository implements CategoryMerchandisingRepositoryInterface
{
    public function __construct(
        private readonly CategoryMerchandisingFactory $factory,
        private readonly CategoryMerchandisingResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
    ) {
    }

    public function getById(int $id): CategoryMerchandisingInterface
    {
        $entity = $this->factory->create();
        $this->resource->load($entity, $id);

        if (!$entity->getId()) {
            throw new NoSuchEntityException(__('Entity with id "%1" does not exist.', $id));
        }

        return $entity;
    }

    public function save(CategoryMerchandisingInterface $entity): CategoryMerchandisingInterface
    {
        try {
            $this->resource->save($entity);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save entity: %1', $e->getMessage()), $e);
        }

        return $entity;
    }

    public function delete(CategoryMerchandisingInterface $entity): bool
    {
        try {
            $this->resource->delete($entity);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete entity: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }
}
