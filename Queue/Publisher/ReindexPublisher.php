<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Queue\Publisher;

use Magento\Framework\MessageQueue\PublisherInterface;

class ReindexPublisher
{
    private const TOPIC = 'typesense.reindex';

    public function __construct(
        private readonly PublisherInterface $publisher,
    ) {
    }

    /**
     * @param int[] $entityIds
     */
    public function publish(
        ?string $entityType = null,
        ?int $storeId = null,
        array $entityIds = [],
        int $attempt = 0,
    ): void
    {
        $message = json_encode([
            'entity_type' => $entityType,
            'store_id' => $storeId,
            'entity_ids' => array_values(array_map('intval', $entityIds)),
            'attempt' => $attempt,
        ]);

        $this->publisher->publish(self::TOPIC, $message);
    }
}
