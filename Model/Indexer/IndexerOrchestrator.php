<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionSettingsProviderInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Api\SelfMaintainedEntityInterface;
use RunAsRoot\TypeSense\Model\Collection\ApplierFailedException;
use RunAsRoot\TypeSense\Model\Collection\CollectionNameResolver;
use RunAsRoot\TypeSense\Model\Collection\ZeroDowntimeService;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class IndexerOrchestrator implements IndexerOrchestratorInterface
{
    public function __construct(
        private readonly EntityIndexerPool $indexerPool,
        private readonly ZeroDowntimeService $zeroDowntimeService,
        private readonly BatchImportService $batchImportService,
        private readonly CollectionNameResolver $nameResolver,
        private readonly TypeSenseConfigInterface $config,
        private readonly IndexableStoreResolver $storeResolver,
        private readonly AliasManagerInterface $aliasManager,
        private readonly CollectionManagerInterface $collectionManager,
        private readonly DocumentDeleter $documentDeleter,
        private readonly LoggerInterface $logger,
        private readonly Emulation $emulation,
        private readonly SchemaChangeHandler $schemaChangeHandler,
    ) {
    }

    /**
     * Reindex a specific entity type, optionally for specific entity IDs.
     *
     * @param int[] $entityIds Empty = full reindex
     */
    public function reindex(string $entityType, array $entityIds = []): void
    {
        $indexer = $this->indexerPool->getIndexer($entityType);

        $changedSchemas = [];
        $applierFailures = [];
        $stores = $this->storeResolver->getStores($entityType);
        try {
            foreach ($stores as $store) {
                $storeId = (int) $store->getId();
                try {
                    $schemaChanged = $this->reindexStore($indexer, $entityType, $store->getCode(), $storeId, $entityIds);
                } catch (ApplierFailedException $e) {
                    // The store keeps its live collection, so the other stores can still be brought up to date.
                    $this->logger->error($e->getMessage(), ['exception' => $e]);
                    $applierFailures[] = $e;
                    continue;
                }
                if ($schemaChanged) {
                    $changedSchemas[] = $this->nameResolver->resolve($entityType, $store->getCode(), $storeId);
                }
            }
        } finally {
            // The stores reindexed before a failing one are live with their new schema.
            if ($entityIds === []) {
                $this->schemaChangeHandler->handle($changedSchemas);
            }
        }

        $this->throwApplierFailures($applierFailures);
    }

    /**
     * Reindex all registered entity types.
     */
    public function reindexAll(): void
    {
        $entityTypes = array_keys($this->indexerPool->getAll());

        $applierFailures = [];
        foreach ($entityTypes as $entityType) {
            try {
                $this->reindex($entityType);
            } catch (ApplierFailedException $e) {
                $applierFailures[] = $e;
            }
        }

        $this->throwApplierFailures($applierFailures);
    }

    /**
     * @param list<ApplierFailedException> $failures
     */
    private function throwApplierFailures(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        if (count($failures) === 1) {
            throw $failures[0];
        }

        $messages = array_map(static fn (ApplierFailedException $e): string => $e->getMessage(), $failures);
        throw new ApplierFailedException(implode("\n", $messages), 0, $failures[0]);
    }

    private function reindexStore(
        EntityIndexerInterface $indexer,
        string $entityType,
        string $storeCode,
        int $storeId,
        array $entityIds,
    ): bool {
        $this->logger->info("Reindexing {$entityType} for store {$storeCode} (ID: {$storeId})");

        // Its documents are written by Typesense itself: a versioned rebuild would discard them.
        if ($indexer instanceof SelfMaintainedEntityInterface) {
            if ($entityIds === []) {
                $indexer->provision($storeId, $storeCode);
            }
            return false;
        }

        // Documents are generated lazily, so building, importing and deleting must all happen inside emulation.
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            if ($entityIds === []) {
                return $this->reindexStoreFull($indexer, $entityType, $storeCode, $storeId);
            }

            $this->reindexStorePartial($indexer, $entityType, $storeCode, $storeId, $entityIds);

            return false;
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    private function reindexStoreFull(
        EntityIndexerInterface $indexer,
        string $entityType,
        string $storeCode,
        int $storeId,
    ): bool {
        $schema = ['fields' => $indexer->getSchemaFields()];
        if ($indexer instanceof CollectionSettingsProviderInterface) {
            $schema += $indexer->getCollectionSettings();
        }
        $zeroDowntime = $this->config->isZeroDowntimeEnabled($storeId);
        $schemaChanged = false;

        if ($zeroDowntime) {
            $collectionName = $this->zeroDowntimeService->startReindex($entityType, $storeCode, $schema, $storeId);
        } else {
            $collectionName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);

            // An existing collection is reused as it is, so only a new one can bring a new schema.
            if (!$this->collectionManager->collectionExists($collectionName)) {
                $this->collectionManager->createCollection($collectionName, ['name' => $collectionName] + $schema);
                $schemaChanged = true;
            }
        }

        try {
            $documents = $indexer->buildDocuments([], $storeId);
            $stats = $this->batchImportService->import($collectionName, $documents, $storeId);
        } catch (\Throwable $e) {
            if ($zeroDowntime) {
                $this->discardFailedBuild($collectionName);
            }
            throw $e;
        }

        if ($zeroDowntime) {
            try {
                $schemaChanged = $this->zeroDowntimeService->finishReindex(
                    $entityType,
                    $storeCode,
                    $collectionName,
                    $storeId,
                );
            } catch (ApplierFailedException $e) {
                // Only this failure is raised before the swap; after it the new collection is live.
                $this->discardFailedBuild($collectionName);
                throw $e;
            }
        }

        $this->logComplete($entityType, $storeCode, $stats);

        return $schemaChanged;
    }

    private function discardFailedBuild(string $collectionName): void
    {
        try {
            $this->collectionManager->deleteCollection($collectionName);
            $this->logger->warning("Deleted partially built collection {$collectionName} after a failed reindex");
        } catch (\Throwable $e) {
            $this->logger->error(
                "Could not delete partially built collection {$collectionName}: {$e->getMessage()}",
                ['exception' => $e],
            );
        }
    }

    private function reindexStorePartial(
        EntityIndexerInterface $indexer,
        string $entityType,
        string $storeCode,
        int $storeId,
        array $entityIds,
    ): void {
        $collectionName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);

        // A partial import into a missing target would create an index holding only the changed documents.
        if (!$this->aliasManager->aliasExists($collectionName)
            && !$this->collectionManager->collectionExists($collectionName)
        ) {
            $this->logger->info(
                "No index {$collectionName} for {$entityType}/{$storeCode}: full reindex required, skipping partial update",
            );
            return;
        }

        $yieldedIds = [];
        $documents = $this->collectYieldedIds($indexer->buildDocuments($entityIds, $storeId), $yieldedIds);
        $stats = $this->batchImportService->import($collectionName, $documents, $storeId);

        // Entities the indexer no longer yields (deleted, disabled, out of scope) must not linger in the live index.
        $staleIds = array_values(array_diff(array_map($indexer->getDocumentId(...), $entityIds), $yieldedIds));
        if ($staleIds !== []) {
            $this->documentDeleter->deleteByIds($collectionName, $staleIds, $storeId);
        }

        $this->logComplete($entityType, $storeCode, $stats);
    }

    /**
     * @param iterable<array<string, mixed>> $documents
     * @param string[] $yieldedIds
     * @return \Generator<int, array<string, mixed>>
     */
    private function collectYieldedIds(iterable $documents, array &$yieldedIds): \Generator
    {
        foreach ($documents as $document) {
            $yieldedIds[] = (string) $document['id'];
            yield $document;
        }
    }

    private function logComplete(string $entityType, string $storeCode, array $stats): void
    {
        $this->logger->info("Reindex complete for {$entityType}/{$storeCode}: {$stats['success']}/{$stats['total']} docs");
    }
}
