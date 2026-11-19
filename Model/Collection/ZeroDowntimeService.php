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

        // A plain collection squatting on the alias name blocks alias creation (pre-versioning installs).
        if ($this->collectionManager->collectionExists($aliasName) && !$this->aliasManager->aliasExists($aliasName)) {
            $this->collectionManager->deleteCollection($aliasName);
            $this->logger->info("One-time migration: deleted plain collection {$aliasName} to make way for the alias");
        }

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

    public function rollback(string $entityType, string $storeCode, ?int $storeId = null): string
    {
        $aliasName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);
        $alias = $this->aliasManager->getAlias($aliasName);
        $currentVersion = $this->parseVersion($aliasName, $alias['collection_name']);
        $versions = $this->listVersions($aliasName);

        $previous = null;
        foreach ($versions as $version => $name) {
            if ($currentVersion !== null && $version < $currentVersion && ($previous === null || $version > $previous)) {
                $previous = $version;
            }
        }

        if ($previous === null) {
            throw new \RuntimeException('No previous version to roll back to');
        }

        $this->aliasManager->upsertAlias($aliasName, $versions[$previous]);
        $this->logger->info("Rolled back alias {$aliasName} → {$versions[$previous]}");

        return $versions[$previous];
    }

    private function getNextVersion(string $aliasName): int
    {
        $highest = 0;
        try {
            $alias = $this->aliasManager->getAlias($aliasName);
            $highest = $this->parseVersion($aliasName, $alias['collection_name']) ?? 0;
        } catch (ObjectNotFound) {
            // Alias doesn't exist yet — start at version 1
        }

        // After a rollback the alias is behind the newest collection; reusing its number would collide.
        $existing = array_keys($this->listVersions($aliasName));

        return max([$highest, ...$existing]) + 1;
    }

    private function cleanupOldVersions(string $aliasName, string $currentCollection): void
    {
        $currentVersion = $this->parseVersion($aliasName, $currentCollection);
        $versions = $this->listVersions($aliasName);

        // Keep the current and the highest lower version so a rollback target always exists.
        $keep = null;
        foreach ($versions as $version => $name) {
            if ($currentVersion !== null && $version < $currentVersion && ($keep === null || $version > $keep)) {
                $keep = $version;
            }
        }

        foreach ($versions as $version => $name) {
            if ($version !== $currentVersion && $version !== $keep) {
                $this->collectionManager->deleteCollection($name);
                $this->logger->info("Deleted old collection: {$name}");
            }
        }
    }

    /**
     * @return array<int, string> version number => collection name
     */
    private function listVersions(string $aliasName): array
    {
        $collections = $this->collectionManager->listCollections();
        $versions = [];
        foreach ($collections as $collection) {
            $version = $this->parseVersion($aliasName, $collection['name']);
            if ($version !== null) {
                $versions[$version] = $collection['name'];
            }
        }

        return $versions;
    }

    private function parseVersion(string $aliasName, string $collectionName): ?int
    {
        if (preg_match('/^' . preg_quote($aliasName, '/') . '_v(\d+)$/', $collectionName, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
