<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Cron;

use Magento\Store\Api\StoreRepositoryInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionPruner;

/**
 * Typesense adds new queries on every analytics flush; they stay hidden until this check has run.
 */
class SuggestionPruneCron
{
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly TypeSenseConfigInterface $config,
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly CollectionManagerInterface $collectionManager,
        private readonly SuggestionPruner $pruner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $stores = $this->storeRepository->getList();
        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $storeCode = (string) $store->getCode();
            if ($storeCode === 'admin' || !$store->getIsActive() || !$this->config->isEnabled($storeId)
                || !$this->config->isSuggestionIndexingEnabled($storeId)
            ) {
                continue;
            }

            try {
                // Not provisioned yet: the suggestion reindex creates and prunes it.
                if (!$this->collectionManager->collectionExists(
                    $this->nameResolver->resolve('suggestion', $storeCode, $storeId),
                )) {
                    continue;
                }
                $this->pruner->prune($storeId, $storeCode);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "TypeSense suggestion pruning failed for store {$storeCode}: {$e->getMessage()}",
                    ['exception' => $e],
                );
            }
        }
    }
}
