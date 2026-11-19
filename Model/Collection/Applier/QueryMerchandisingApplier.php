<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection\Applier;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\Data\QueryMerchandisingInterface;
use RunAsRoot\TypeSense\Api\PostSwapApplierInterface;
use RunAsRoot\TypeSense\Api\QueryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Model\Curation\QueryMerchandisingSync;

class QueryMerchandisingApplier implements PostSwapApplierInterface
{
    public function __construct(
        private readonly QueryMerchandisingRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly QueryMerchandisingSync $sync,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(string $entityType, int $storeId, string $storeCode, string $collectionName): void
    {
        if ($entityType !== 'product') {
            return;
        }

        $queryIds = $this->getQueryIds($storeId);

        foreach ($queryIds as $queryId) {
            try {
                $this->sync->sync($queryId, $storeId, $storeCode, $collectionName);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Failed to apply query merchandising %d / store %d to %s: %s',
                    $queryId,
                    $storeId,
                    $collectionName,
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * @return int[]
     */
    private function getQueryIds(int $storeId): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('store_id', $storeId)
            ->create();
        $rules = $this->repository->getList($searchCriteria)->getItems();

        $queryIds = array_map(
            static fn(QueryMerchandisingInterface $rule): int => (int) $rule->getId(),
            $rules,
        );

        return array_values(array_unique($queryIds));
    }
}
