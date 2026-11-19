<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use Magento\Framework\App\Area;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
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
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly AliasManagerInterface $aliasManager,
        private readonly CollectionManagerInterface $collectionManager,
        private readonly DocumentDeleter $documentDeleter,
        private readonly LoggerInterface $logger,
        private readonly Emulation $emulation,
    ) {
    }

    /**
     * Reindex a specific entity type, optionally for specific entity IDs.
     *
     * @param int[] $entityIds Empty = full reindex
     */
    public function reindex(string $entityType, array $entityIds = []): void
    {
        if (!$this->config->isEnabled()) {
            $this->logger->info("TypeSense is disabled, skipping reindex for {$entityType}");
            return;
        }

        $indexer = $this->indexerPool->getIndexer($entityType);

        $stores = $this->storeRepository->getList();

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $storeCode = $store->getCode();

            if ($storeCode === 'admin') {
                continue;
            }

            if (!$store->getIsActive() || !$this->config->isEnabled($storeId)) {
                $this->logger->info("Skipping {$entityType} for store {$storeCode}: inactive or TypeSense disabled");
                continue;
            }

            $this->reindexStore($indexer, $entityType, $storeCode, $storeId, $entityIds);
        }
    }

    /**
     * Reindex all registered entity types.
     */
    public function reindexAll(): void
    {
        foreach ($this->indexerPool->getAll() as $entityType => $indexer) {
            $this->reindex($entityType);
        }
    }

    private function reindexStore(
        EntityIndexerInterface $indexer,
        string $entityType,
        string $storeCode,
        int $storeId,
        array $entityIds,
    ): void {
        $this->logger->info("Reindexing {$entityType} for store {$storeCode} (ID: {$storeId})");

        // Documents are generated lazily, so building, importing and deleting must all happen inside emulation.
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            if ($entityIds === []) {
                $this->reindexStoreFull($indexer, $entityType, $storeCode, $storeId);
                return;
            }

            $this->reindexStorePartial($indexer, $entityType, $storeCode, $storeId, $entityIds);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    private function reindexStoreFull(
        EntityIndexerInterface $indexer,
        string $entityType,
        string $storeCode,
        int $storeId,
    ): void {
        $schemaFields = $indexer->getSchemaFields();
        $zeroDowntime = $this->config->isZeroDowntimeEnabled($storeId);

        if ($zeroDowntime) {
            $collectionName = $this->zeroDowntimeService->startReindex(
                $entityType,
                $storeCode,
                ['fields' => $schemaFields],
                $storeId,
            );
        } else {
            $collectionName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);

            if (!$this->collectionManager->collectionExists($collectionName)) {
                $this->collectionManager->createCollection(
                    $collectionName,
                    ['name' => $collectionName, 'fields' => $schemaFields],
                );
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
            $this->zeroDowntimeService->finishReindex($entityType, $storeCode, $collectionName, $storeId);
        }

        $this->logComplete($entityType, $storeCode, $stats);
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
