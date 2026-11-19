<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Synonym;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\Synonym as SynonymResource;
use RunAsRoot\TypeSense\Model\ResourceModel\Synonym\CollectionFactory;

class SynonymRepository implements SynonymRepositoryInterface
{
    public function __construct(
        private readonly SynonymResource $resource,
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    public function save(SynonymInterface $synonym): SynonymInterface
    {
        try {
            $this->resource->save($synonym);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save synonym: %1', $e->getMessage()), $e);
        }

        return $synonym;
    }

    public function delete(SynonymInterface $synonym): void
    {
        try {
            $this->resource->delete($synonym);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete synonym: %1', $e->getMessage()), $e);
        }
    }

    public function getByCollection(string $collection): array
    {
        $synonyms = $this->collectionFactory->create();
        $synonyms->addFieldToFilter('collection', $collection);
        $synonyms->setOrder('synonym_id', 'ASC');

        /** @var SynonymInterface[] $items */
        $items = $synonyms->getItems();

        return array_values($items);
    }

    public function getByTypesenseId(string $collection, string $typesenseId): SynonymInterface
    {
        $synonyms = $this->collectionFactory->create();
        $synonyms->addFieldToFilter('collection', $collection);
        $synonyms->addFieldToFilter('typesense_id', $typesenseId);
        $synonyms->setPageSize(1);

        $synonym = $synonyms->getFirstItem();
        if (!$synonym instanceof SynonymInterface || $synonym->getId() === null) {
            throw new NoSuchEntityException(
                __('Synonym "%1" does not exist for collection "%2".', $typesenseId, $collection),
            );
        }

        return $synonym;
    }
}
