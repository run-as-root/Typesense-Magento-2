<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection\Applier;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\PostSwapApplierInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Model\Synonym\SynonymPusher;

class SynonymApplier implements PostSwapApplierInterface
{
    public function __construct(
        private readonly SynonymRepositoryInterface $repository,
        private readonly SynonymPusher $pusher,
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(string $entityType, int $storeId, string $storeCode, string $collectionName): void
    {
        $aliasName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);
        $synonyms = $this->repository->getByCollection($aliasName);

        foreach ($synonyms as $synonym) {
            try {
                $this->pusher->push($synonym, $collectionName);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Failed to apply synonym %s to collection %s: %s',
                    $synonym->getTypesenseId(),
                    $collectionName,
                    $e->getMessage(),
                ));
            }
        }
    }
}
