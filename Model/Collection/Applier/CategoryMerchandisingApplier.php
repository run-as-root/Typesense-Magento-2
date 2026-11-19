<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection\Applier;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Api\PostSwapApplierInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;

class CategoryMerchandisingApplier implements PostSwapApplierInterface
{
    public function __construct(
        private readonly CategoryMerchandisingRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CategoryMerchandisingSync $sync,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(string $entityType, int $storeId, string $storeCode, string $collectionName): void
    {
        if ($entityType !== 'product') {
            return;
        }

        $categoryIds = $this->getCategoryIds($storeId);

        foreach ($categoryIds as $categoryId) {
            try {
                $this->sync->sync($categoryId, $storeId, $storeCode, $collectionName);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Failed to apply category merchandising for category %d / store %d to %s: %s',
                    $categoryId,
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
    private function getCategoryIds(int $storeId): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('store_id', $storeId)
            ->create();
        $rows = $this->repository->getList($searchCriteria)->getItems();

        $categoryIds = array_map(
            static fn(CategoryMerchandisingInterface $row): int => $row->getCategoryId(),
            $rows,
        );

        return array_values(array_unique($categoryIds));
    }
}
