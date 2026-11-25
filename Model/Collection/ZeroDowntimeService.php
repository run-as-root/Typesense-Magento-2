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

    /**
     * @return bool whether the new collection's schema differs from the one that was live (true for a first build)
     * @throws ApplierFailedException when the collection state could not be re-created on a collection that
     *     would replace a live one; the alias keeps pointing at the live collection
     */
    public function finishReindex(string $entityType, string $storeCode, string $collectionName, ?int $storeId = null): bool
    {
        $aliasName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);

        // Read before the swap: the version that was live is the only safe rollback target. The
        // highest lower number may be a half-built orphan or the version rolled away from.
        $previouslyLive = $this->getAliasTarget($aliasName);

        // Synonyms and overrides live on the collection, so the new one must carry them before it goes live.
        $this->runAppliers($entityType, $storeCode, $collectionName, $storeId, $previouslyLive !== null);

        // A plain collection squatting on the alias name blocks alias creation (pre-versioning installs).
        if ($this->collectionManager->collectionExists($aliasName) && !$this->aliasManager->aliasExists($aliasName)) {
            $this->collectionManager->deleteCollection($aliasName);
            $this->logger->info("One-time migration: deleted plain collection {$aliasName} to make way for the alias");
        }

        $schemaChanged = $previouslyLive === null || $this->schemaDiffers($previouslyLive, $collectionName);

        $this->aliasManager->upsertAlias($aliasName, $collectionName);
        $this->logger->info("Swapped alias {$aliasName} → {$collectionName}");

        $this->cleanupOldVersions($aliasName, $collectionName, $previouslyLive);

        return $schemaChanged;
    }

    // Compares the retrieved schemas, so fields that a pattern field (category_position_.*) created count too.
    private function schemaDiffers(string $previousCollection, string $newCollection): bool
    {
        try {
            $previous = $this->collectionManager->getCollection($previousCollection);
            $new = $this->collectionManager->getCollection($newCollection);
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Could not compare the schemas of {$previousCollection} and {$newCollection}: {$e->getMessage()}",
                ['exception' => $e],
            );

            return true;
        }

        return $this->fieldSignature($previous) !== $this->fieldSignature($new);
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, array<string, mixed>> field name => normalized definition
     */
    private function fieldSignature(array $collection): array
    {
        $signature = [];
        $fields = is_array($collection['fields'] ?? null) ? $collection['fields'] : [];
        foreach ($fields as $field) {
            ksort($field);
            $signature[(string) ($field['name'] ?? '')] = $field;
        }
        ksort($signature);

        return $signature;
    }

    private function runAppliers(
        string $entityType,
        string $storeCode,
        string $collectionName,
        ?int $storeId,
        bool $replacesLiveCollection,
    ): void {
        if ($storeId === null) {
            $this->logger->info(
                "No store id for {$entityType}/{$storeCode}: skipping post-swap appliers on {$collectionName}",
            );
            return;
        }

        $failures = [];
        $firstFailure = null;
        foreach ($this->appliers as $code => $applier) {
            try {
                $applier->apply($entityType, $storeId, $storeCode, $collectionName);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Post-swap applier {$code} failed on {$collectionName}: {$e->getMessage()}",
                    ['exception' => $e],
                );
                $failures[] = "{$code}: {$e->getMessage()}";
                $firstFailure ??= $e;
            }
        }

        // A first build has nothing live to protect: serving it without synonyms beats serving no search at all.
        if ($failures === [] || !$replacesLiveCollection) {
            return;
        }

        throw new ApplierFailedException(
            sprintf(
                'Could not re-create the collection state on %s (%s); the previous collection stays live',
                $collectionName,
                implode('; ', $failures),
            ),
            0,
            $firstFailure,
        );
    }

    public function rollback(string $entityType, string $storeCode, ?int $storeId = null): string
    {
        $aliasName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);
        $alias = $this->aliasManager->getAlias($aliasName);
        $currentVersion = $this->parseVersion($aliasName, $alias['collection_name']);
        $versions = $this->listVersions($aliasName);

        $previous = $this->findRollbackTarget($versions, $currentVersion);

        if ($previous === null) {
            throw new \RuntimeException('No previous version to roll back to');
        }

        $this->aliasManager->upsertAlias($aliasName, $versions[$previous]);
        $this->logger->info("Rolled back alias {$aliasName} → {$versions[$previous]}");

        return $versions[$previous];
    }

    private function getNextVersion(string $aliasName): int
    {
        $target = $this->getAliasTarget($aliasName);
        $highest = $target === null ? 0 : ($this->parseVersion($aliasName, $target) ?? 0);

        // After a rollback the alias is behind the newest collection; reusing its number would collide.
        $existing = array_keys($this->listVersions($aliasName));

        return max([$highest, ...$existing]) + 1;
    }

    private function getAliasTarget(string $aliasName): ?string
    {
        try {
            $alias = $this->aliasManager->getAlias($aliasName);
        } catch (ObjectNotFound) {
            return null;
        }

        return $alias['collection_name'] ?? null;
    }

    private function cleanupOldVersions(string $aliasName, string $currentCollection, ?string $previouslyLive): void
    {
        $versions = $this->listVersions($aliasName);

        foreach ($versions as $name) {
            if ($name !== $currentCollection && $name !== $previouslyLive) {
                $this->collectionManager->deleteCollection($name);
                $this->logger->info("Deleted old collection: {$name}");
            }
        }
    }

    /**
     * Prefers the highest version below the current one; after a rollback only a higher one may remain.
     *
     * @param array<int, string> $versions
     */
    private function findRollbackTarget(array $versions, ?int $currentVersion): ?int
    {
        $others = array_keys($versions);
        if ($currentVersion !== null) {
            $others = array_values(array_diff($others, [$currentVersion]));
        }

        if ($others === []) {
            return null;
        }

        $below = array_filter($others, static fn (int $version): bool => $currentVersion !== null && $version < $currentVersion);

        return $below !== [] ? max($below) : max($others);
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
