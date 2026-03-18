<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use Magento\Store\Api\StoreRepositoryInterface;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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

        foreach ($this->storeRepository->getList() as $store) {
            $storeId = (int) $store->getId();
            $storeCode = $store->getCode();

            if ($storeCode === 'admin') {
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

        $schema = ['fields' => $indexer->getSchemaFields()];

        if ($this->config->isZeroDowntimeEnabled($storeId)) {
            $collectionName = $this->zeroDowntimeService->startReindex($entityType, $storeCode, $schema, $storeId);
        } else {
            $collectionName = $this->nameResolver->resolve($entityType, $storeCode, $storeId);
            // For non-zero-downtime, we'd need to drop and recreate or just upsert into existing
            // For now, this creates the collection if it doesn't exist
        }

        $documents = $indexer->buildDocuments($entityIds, $storeId);
        $stats = $this->batchImportService->import($collectionName, $documents, $storeId);

        if ($this->config->isZeroDowntimeEnabled($storeId)) {
            $this->zeroDowntimeService->finishReindex($entityType, $storeCode, $collectionName, $storeId);
        }

        $this->logger->info("Reindex complete for {$entityType}/{$storeCode}: {$stats['success']}/{$stats['total']} docs");
    }
}
