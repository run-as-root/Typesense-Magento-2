<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\PostSwapApplierInterface;
use Typesense\Exceptions\ObjectNotFound;

class ZeroDowntimeService
{
    /**
     * @param array<string, PostSwapApplierInterface> $appliers
     */
    public function __construct(
        private readonly CollectionManagerInterface $collectionManager,
        private readonly AliasManagerInterface $aliasManager,
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly LoggerInterface $logger,
        private readonly array $appliers = [],
    ) {
    }

    public function startReindex(string $entityType, string $storeCode, array $schema, ?int $storeId = null): string
    {
        $aliasName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);
        $version = $this->getNextVersion($aliasName);
        $collectionName = $this->nameResolver->resolveVersioned($entityType, $storeCode, $version, $storeId);

        $schema['name'] = $collectionName;
        $this->collectionManager->createCollection($collectionName, $schema);

        $this->logger->info("Created versioned collection: {$collectionName}");

        return $collectionName;
    }

    public function finishReindex(string $entityType, string $storeCode, string $collectionName, ?int $storeId = null): void
    {
        $aliasName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);

        // Synonyms and overrides live on the collection, so the new one must carry them before it goes live.
        $this->runAppliers($entityType, $storeCode, $collectionName, $storeId);

        $this->aliasManager->upsertAlias($aliasName, $collectionName);
        $this->logger->info("Swapped alias {$aliasName} → {$collectionName}");

        $this->cleanupOldVersions($aliasName, $collectionName);
    }

    private function runAppliers(string $entityType, string $storeCode, string $collectionName, ?int $storeId): void
    {
        if ($storeId === null) {
            $this->logger->info(
                "No store id for {$entityType}/{$storeCode}: skipping post-swap appliers on {$collectionName}",
            );
            return;
        }

        foreach ($this->appliers as $code => $applier) {
            try {
                $applier->apply($entityType, $storeId, $storeCode, $collectionName);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Post-swap applier {$code} failed on {$collectionName}: {$e->getMessage()}",
                    ['exception' => $e],
                );
            }
        }
    }

    private function getNextVersion(string $aliasName): int
    {
        try {
            $alias = $this->aliasManager->getAlias($aliasName);
            $currentCollection = $alias['collection_name'];
            if (preg_match('/_v(\d+)$/', $currentCollection, $matches)) {
                return (int) $matches[1] + 1;
            }
        } catch (ObjectNotFound) {
            // Alias doesn't exist yet — start at version 1
        }

        return 1;
    }

    private function cleanupOldVersions(string $aliasName, string $currentCollection): void
    {
        $collections = $this->collectionManager->listCollections();
        $prefix = preg_replace('/_v\d+$/', '', $currentCollection) . '_v';

        foreach ($collections as $collection) {
            $name = $collection['name'];
            if (str_starts_with($name, $prefix) && $name !== $currentCollection) {
                $this->collectionManager->deleteCollection($name);
                $this->logger->info("Deleted old collection: {$name}");
            }
        }
    }
}
