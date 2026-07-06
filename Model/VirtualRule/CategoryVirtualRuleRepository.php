<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule\CollectionFactory;

class CategoryVirtualRuleRepository implements CategoryVirtualRuleRepositoryInterface
{
    public function __construct(
        private readonly CategoryVirtualRuleFactory $factory,
        private readonly CategoryVirtualRuleResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    public function getById(int $id): CategoryVirtualRuleInterface
    {
        $entity = $this->factory->create();
        $this->resource->load($entity, $id);

        if (!$entity->getId()) {
            throw new NoSuchEntityException(__('Entity with id "%1" does not exist.', $id));
        }

        return $entity;
    }

    public function save(CategoryVirtualRuleInterface $entity): CategoryVirtualRuleInterface
    {
        try {
            $this->resource->save($entity);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save entity: %1', $e->getMessage()), $e);
        }

        return $entity;
    }

    public function delete(CategoryVirtualRuleInterface $entity): bool
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
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function findByCategoryAndStore(int $categoryId, int $storeId): ?CategoryVirtualRuleInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('category_id', $categoryId)
            ->addFilter('store_id', $storeId)
            ->create();

        $items = array_values($this->getList($searchCriteria)->getItems());

        return $items[0] ?? null;
    }
}
