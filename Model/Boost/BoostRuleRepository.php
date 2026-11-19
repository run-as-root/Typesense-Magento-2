<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Boost;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule as BoostRuleResource;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\CollectionFactory;

class BoostRuleRepository implements BoostRuleRepositoryInterface
{
    public function __construct(
        private readonly BoostRuleFactory $factory,
        private readonly BoostRuleResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
    ) {
    }

    public function getById(int $id): BoostRuleInterface
    {
        $entity = $this->factory->create();
        $this->resource->load($entity, $id);

        if (!$entity->getId()) {
            throw new NoSuchEntityException(__('Boosting rule with id "%1" does not exist.', $id));
        }

        return $entity;
    }

    public function save(BoostRuleInterface $entity): BoostRuleInterface
    {
        try {
            $this->resource->save($entity);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save boosting rule: %1', $e->getMessage()), $e);
        }

        return $entity;
    }

    public function delete(BoostRuleInterface $entity): bool
    {
        try {
            $this->resource->delete($entity);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete boosting rule: %1', $e->getMessage()), $e);
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

    public function getActiveForStore(int $storeId, string $date): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', ['in' => array_values(array_unique([0, $storeId]))]);
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->addFieldToFilter('from_date', [['null' => true], ['lteq' => $date]]);
        $collection->addFieldToFilter('to_date', [['null' => true], ['gteq' => $date]]);
        $collection->setOrder('weight', DataCollection::SORT_ORDER_DESC);
        // Stable order for equal weights, so the compiled sort expression doesn't flap between requests.
        $collection->setOrder('rule_id', DataCollection::SORT_ORDER_ASC);

        /** @var BoostRuleInterface[] $rules */
        $rules = $collection->getItems();

        return array_values($rules);
    }
}
