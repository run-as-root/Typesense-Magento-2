<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Queue\Consumer;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Queue\Publisher\ReindexPublisher;

class ReindexConsumer
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly IndexerOrchestratorInterface $orchestrator,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ReindexPublisher $publisher,
    ) {
    }

    public function process(string $message): void
    {
        $data = json_decode($message, true);
        $data = is_array($data) ? $data : [];
        $entityType = $data['entity_type'] ?? null;
        $entityIds = array_map('intval', $data['entity_ids'] ?? []);

        try {
            if ($entityType) {
                $this->orchestrator->reindex($entityType, $entityIds);
            } else {
                $this->orchestrator->reindexAll();
            }
        } catch (\Throwable $e) {
            $this->logger->error('TypeSense queue reindex failed: ' . $e->getMessage(), [
                'message' => $message,
            ]);

            // Never rethrow: Magento's consumer rejects the message without requeue and mview has
            // already advanced, so the ids would be lost. A full reindex is too costly to loop on.
            if ($entityType && $entityIds !== []) {
                $this->retry($entityType, $data, $entityIds);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param int[] $entityIds
     */
    private function retry(string $entityType, array $data, array $entityIds): void
    {
        $nextAttempt = (int) ($data['attempt'] ?? 0) + 1;

        if ($nextAttempt >= self::MAX_ATTEMPTS) {
            $this->logger->error(sprintf(
                'TypeSense partial reindex of %s gave up after %d attempts; the next full reindex will pick these ids up.',
                $entityType,
                self::MAX_ATTEMPTS,
            ), [
                'entity_type' => $entityType,
                'entity_ids' => $entityIds,
            ]);

            return;
        }

        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        $this->publisher->publish($entityType, $storeId, $entityIds, $nextAttempt);
    }
}
