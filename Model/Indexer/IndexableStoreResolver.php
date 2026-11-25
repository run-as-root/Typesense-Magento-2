<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class IndexableStoreResolver
{
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Stores the entity type is indexed in. No default-scope gate: a module or entity type enabled
     * for a single store view must still be indexed there.
     *
     * @return list<StoreInterface>
     */
    public function getStores(string $entityType): array
    {
        $indexable = [];
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

            if (!$this->config->isEntityIndexingEnabled($entityType, $storeId)) {
                $this->logger->info("Skipping {$entityType} for store {$storeCode}: {$entityType} indexing disabled");
                continue;
            }

            $indexable[] = $store;
        }

        return $indexable;
    }
}
