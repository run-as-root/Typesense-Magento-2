<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Queue\Publisher;

use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Queue\Publisher\ReindexPublisher;

final class ReindexPublisherTest extends TestCase
{
    public function test_publish_defaults_attempt_to_zero(): void
    {
        $queue = $this->createMock(PublisherInterface::class);
        $queue->expects(self::once())->method('publish')->with(
            'typesense.reindex',
            json_encode(['entity_type' => 'product', 'store_id' => null, 'entity_ids' => [3], 'attempt' => 0]),
        );

        (new ReindexPublisher($queue))->publish('product', null, [3]);
    }

    public function test_publish_carries_attempt(): void
    {
        $queue = $this->createMock(PublisherInterface::class);
        $queue->expects(self::once())->method('publish')->with(
            'typesense.reindex',
            json_encode(['entity_type' => 'product', 'store_id' => 1, 'entity_ids' => [3, 4], 'attempt' => 2]),
        );

        (new ReindexPublisher($queue))->publish('product', 1, ['3', 4], 2);
    }
}
