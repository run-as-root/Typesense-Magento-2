<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Queue\Consumer;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Queue\Consumer\ReindexConsumer;

final class ReindexConsumerTest extends TestCase
{
    private IndexerOrchestratorInterface&MockObject $orchestrator;
    private StoreManagerInterface&MockObject $storeManager;
    private LoggerInterface&MockObject $logger;
    private ReindexConsumer $consumer;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(IndexerOrchestratorInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->consumer = new ReindexConsumer(
            $this->orchestrator,
            $this->storeManager,
            $this->logger,
        );
    }

    public function test_process_calls_reindex_with_entity_type(): void
    {
        $message = json_encode(['entity_type' => 'product', 'store_id' => 1]);

        $this->orchestrator->expects(self::once())
            ->method('reindex')
            ->with('product');

        $this->orchestrator->expects(self::never())->method('reindexAll');

        $this->consumer->process($message);
    }

    public function test_process_calls_reindex_all_when_no_entity_type(): void
    {
        $message = json_encode(['entity_type' => null, 'store_id' => null]);

        $this->orchestrator->expects(self::once())->method('reindexAll');
        $this->orchestrator->expects(self::never())->method('reindex');

        $this->consumer->process($message);
    }

    public function test_process_logs_error_on_exception(): void
    {
        $message = json_encode(['entity_type' => 'product', 'store_id' => 1]);

        $this->orchestrator->method('reindex')
            ->willThrowException(new \RuntimeException('Typesense unavailable'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Typesense unavailable'),
                self::arrayHasKey('message'),
            );

        $this->consumer->process($message);
    }
}
