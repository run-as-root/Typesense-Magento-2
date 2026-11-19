<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Cron;

use Magento\Store\Api\StoreRepositoryInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class ReindexCron
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly IndexerOrchestratorInterface $orchestrator,
        private readonly LoggerInterface $logger,
        private readonly StoreRepositoryInterface $storeRepository,
    ) {
    }

    public function execute(): void
    {
        if (!$this->isAnyStoreScheduled()) {
            return;
        }

        try {
            $this->orchestrator->reindexAll();
        } catch (\Exception $e) {
            $this->logger->error('TypeSense cron reindex failed: ' . $e->getMessage());
        }
    }

    private function isAnyStoreScheduled(): bool
    {
        if ($this->config->isEnabled() && $this->config->isCronEnabled()) {
            return true;
        }

        // Settings may exist only at store-view scope; the orchestrator skips the disabled stores.
        $stores = $this->storeRepository->getList();
        foreach ($stores as $store) {
            if ($store->getCode() === 'admin') {
                continue;
            }
            $storeId = (int) $store->getId();
            if ($this->config->isEnabled($storeId) && $this->config->isCronEnabled($storeId)) {
                return true;
            }
        }

        return false;
    }
}
