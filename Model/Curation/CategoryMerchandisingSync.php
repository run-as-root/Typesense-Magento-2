<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Curation;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;

class CategoryMerchandisingSync
{
    public function __construct(
        private readonly OverrideManagerInterface $overrideManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly CategoryMerchandisingRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(int $categoryId, int $storeId, string $storeCode): void
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('category_id', $categoryId)
            ->addFilter('store_id', $storeId)
            ->create();

        $searchResults = $this->repository->getList($searchCriteria);
        $rules = $searchResults->getItems();

        $collectionName = $this->collectionNameResolver->resolve('product', $storeCode, $storeId);
        $overrideId = sprintf('cat_merch_%d_%d', $categoryId, $storeId);

        if (empty($rules)) {
            $this->logger->info(sprintf(
                'No merchandising rules found for category %d / store %d — deleting override %s',
                $categoryId,
                $storeId,
                $overrideId,
            ));
            $this->overrideManager->deleteOverride($collectionName, $overrideId);
            return;
        }

        $pinRules = array_filter($rules, fn(CategoryMerchandisingInterface $r) => $r->getAction() === 'pin');
        $hideRules = array_filter($rules, fn(CategoryMerchandisingInterface $r) => $r->getAction() === 'hide');

        $payload = [
            'rule' => [
                'query' => '*',
                'match' => 'exact',
                'filter_by' => "category_ids:={$categoryId}",
            ],
            'includes' => array_values(array_map(
                fn(CategoryMerchandisingInterface $r) => ['id' => (string) $r->getProductId(), 'position' => $r->getPosition()],
                $pinRules,
            )),
            'excludes' => array_values(array_map(
                fn(CategoryMerchandisingInterface $r) => ['id' => (string) $r->getProductId()],
                $hideRules,
            )),
        ];

        $this->logger->info(sprintf(
            'Upserting override %s on collection %s (%d pins, %d hides)',
            $overrideId,
            $collectionName,
            count($pinRules),
            count($hideRules),
        ));

        $this->overrideManager->createOverride($collectionName, $overrideId, $payload);
    }
}
