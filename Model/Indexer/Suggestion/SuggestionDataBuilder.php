<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

use Magento\Search\Model\Query;
use Magento\Search\Model\ResourceModel\Query\Collection as QueryCollection;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory as QueryCollectionFactory;

/**
 * Note: Suggestions are updated on-search, not on entity save, so no mview subscription is needed.
 * Use cron or manual reindex to keep suggestions in sync.
 */
class SuggestionDataBuilder
{
    public function __construct(
        private readonly QueryCollectionFactory $collectionFactory,
    ) {
    }

    /**
     * Build a Typesense document array from a search query.
     *
     * @return array<string, mixed>
     */
    public function build(Query $query, int $storeId): array
    {
        $queryId = (int) $query->getId();

        return [
            'id' => (string) $queryId,
            'query' => (string) $query->getQueryText(),
            'num_results' => (int) $query->getNumResults(),
            'popularity' => (int) $query->getPopularity(),
        ];
    }

    /**
     * Load a search query collection filtered by entity IDs.
     *
     * @param int[] $entityIds
     */
    public function getQueryCollection(array $entityIds, int $storeId): QueryCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->addFieldToFilter('num_results', ['gt' => 0]);

        if ($entityIds !== []) {
            $collection->addFieldToFilter('query_id', ['in' => $entityIds]);
        }

        return $collection;
    }
}
