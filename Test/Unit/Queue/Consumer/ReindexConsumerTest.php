<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Queue\Consumer;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Queue\Consumer\ReindexConsumer;
use RunAsRoot\TypeSense\Queue\Publisher\ReindexPublisher;

final class ReindexConsumerTest extends TestCase
{
    private IndexerOrchestratorInterface&MockObject $orchestrator;
    private StoreManagerInterface&MockObject $storeManager;
    private LoggerInterface&MockObject $logger;
    private ReindexPublisher&MockObject $publisher;
    private ReindexConsumer $consumer;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(IndexerOrchestratorInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->publisher = $this->createMock(ReindexPublisher::class);

        $this->consumer = new ReindexConsumer(
            $this->orchestrator,
            $this->storeManager,
            $this->logger,
            $this->publisher,
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

    public function test_consumer_passes_entity_ids_to_orchestrator(): void
    {
        $message = json_encode(['entity_type' => 'product', 'entity_ids' => [7, 8]]);

        $this->orchestrator->expects(self::once())->method('reindex')->with('product', [7, 8]);

        $this->consumer->process($message);
    }

    public function test_partial_failure_republishes_with_next_attempt_instead_of_throwing(): void
    {
        $message = json_encode(['entity_type' => 'product', 'store_id' => 2, 'entity_ids' => [7, 9]]);

        $this->orchestrator->method('reindex')
            ->willThrowException(new \RuntimeException('Typesense unavailable'));
        $this->publisher->expects(self::once())->method('publish')->with('product', 2, [7, 9], 1);

        $this->consumer->process($message);
    }

    public function test_partial_failure_increments_existing_attempt(): void
    {
        $message = json_encode(['entity_type' => 'product', 'entity_ids' => [7], 'attempt' => 2]);

        $this->orchestrator->method('reindex')
            ->willThrowException(new \RuntimeException('Typesense unavailable'));
        $this->publisher->expects(self::once())->method('publish')->with('product', null, [7], 3);

        $this->consumer->process($message);
    }

    public function test_partial_failure_on_fifth_attempt_logs_ids_and_stops_retrying(): void
    {
        $message = json_encode(['entity_type' => 'product', 'entity_ids' => [7, 9], 'attempt' => 4]);

        $this->orchestrator->method('reindex')
            ->willThrowException(new \RuntimeException('Typesense unavailable'));
        $this->publisher->expects(self::never())->method('publish');

        $errors = [];
        $this->logger->method('error')->willReturnCallback(
            function (string $msg, array $context = []) use (&$errors): void {
                $errors[] = [$msg, $context];
            },
        );

        $this->consumer->process($message);

        $giveUp = array_values(array_filter($errors, fn(array $e): bool => ($e[1]['entity_ids'] ?? null) === [7, 9]));
        self::assertCount(1, $giveUp);
    }

    public function test_full_reindex_failure_only_logs(): void
    {
        $message = json_encode(['entity_type' => null]);

        $this->orchestrator->method('reindexAll')
            ->willThrowException(new \RuntimeException('Typesense unavailable'));
        $this->publisher->expects(self::never())->method('publish');
        $this->logger->expects(self::once())->method('error');

        $this->consumer->process($message);
    }
}
