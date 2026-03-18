<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Curation;

use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;
use RunAsRoot\TypeSense\Api\QueryMerchandisingRepositoryInterface;

class QueryMerchandisingSync
{
    public function __construct(
        private readonly OverrideManagerInterface $overrideManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly QueryMerchandisingRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(int $queryMerchandisingId, int $storeId, string $storeCode): void
    {
        $collectionName = $this->collectionNameResolver->resolve('product', $storeCode, $storeId);
        $overrideId = sprintf('query_merch_%d', $queryMerchandisingId);

        try {
            $queryRule = $this->repository->getById($queryMerchandisingId);
        } catch (NoSuchEntityException) {
            $this->logger->info(sprintf(
                'Query merchandising rule %d no longer exists — deleting override %s',
                $queryMerchandisingId,
                $overrideId,
            ));
            $this->overrideManager->deleteOverride($collectionName, $overrideId);
            return;
        }

        if (!$queryRule->isActive()) {
            $this->logger->info(sprintf(
                'Query merchandising rule %d is inactive — deleting override %s',
                $queryMerchandisingId,
                $overrideId,
            ));
            $this->overrideManager->deleteOverride($collectionName, $overrideId);
            return;
        }

        $payload = [
            'rule' => [
                'query' => $queryRule->getQuery(),
                'match' => $queryRule->getMatchType(),
            ],
            'includes' => $queryRule->getIncludes(),
            'excludes' => $queryRule->getExcludes(),
        ];

        $this->logger->info(sprintf(
            'Upserting override %s on collection %s for query "%s"',
            $overrideId,
            $collectionName,
            $queryRule->getQuery(),
        ));

        $this->overrideManager->createOverride($collectionName, $overrideId, $payload);
    }
}
