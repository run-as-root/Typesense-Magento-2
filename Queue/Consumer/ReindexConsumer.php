<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Queue\Consumer;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;

class ReindexConsumer
{
    public function __construct(
        private readonly IndexerOrchestratorInterface $orchestrator,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(string $message): void
    {
        $entityIds = [];

        try {
            $data = json_decode($message, true);
            $entityType = $data['entity_type'] ?? null;
            $entityIds = array_map('intval', $data['entity_ids'] ?? []);

            if ($entityType) {
                $this->orchestrator->reindex($entityType, $entityIds);
            } else {
                $this->orchestrator->reindexAll();
            }
        } catch (\Exception $e) {
            $this->logger->error('TypeSense queue reindex failed: ' . $e->getMessage(), [
                'message' => $message,
            ]);

            // Partial reindexes are rethrown so the queue retries them; a full reindex is too costly to loop on failure.
            if ($entityIds !== []) {
                throw $e;
            }
        }
    }
}
