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

    public function publish(?string $entityType = null, ?int $storeId = null): void
    {
        $message = json_encode([
            'entity_type' => $entityType,
            'store_id' => $storeId,
        ]);

        $this->publisher->publish(self::TOPIC, $message);
    }
}
