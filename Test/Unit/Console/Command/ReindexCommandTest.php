<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Console\Command\ReindexCommand;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;
use RunAsRoot\TypeSense\Model\Indexer\IndexableStoreResolver;
use Magento\Store\Api\Data\StoreInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ReindexCommandTest extends TestCase
{
    private IndexerOrchestratorInterface&MockObject $orchestrator;
    private EntityIndexerPool&MockObject $indexerPool;
    private State&MockObject $appState;
    private IndexableStoreResolver&MockObject $storeResolver;
    private ReindexCommand $command;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(IndexerOrchestratorInterface::class);
        $this->indexerPool = $this->createMock(EntityIndexerPool::class);
        $this->appState = $this->createMock(State::class);
        $this->storeResolver = $this->createMock(IndexableStoreResolver::class);
        $this->storeResolver->method('getStores')->willReturnCallback(
            fn (string $entityType): array => $entityType === 'customer' ? [] : [$this->createMock(StoreInterface::class)],
        );
        $this->command = new ReindexCommand($this->orchestrator, $this->indexerPool, $this->appState, $this->storeResolver);
    }

    public function test_reindex_all_is_called_when_no_entity_option(): void
    {
        $this->orchestrator->expects(self::once())
            ->method('reindexAll');

        $this->orchestrator->expects(self::never())
            ->method('reindex');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Reindexing all entity types', $tester->getDisplay());
    }

    public function test_reindex_specific_entity_when_entity_option_provided(): void
    {
        $this->indexerPool->method('hasIndexer')->with('product')->willReturn(true);

        $this->orchestrator->expects(self::once())
            ->method('reindex')
            ->with('product');

        $this->orchestrator->expects(self::never())
            ->method('reindexAll');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--entity' => 'product']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('product', $tester->getDisplay());
    }

    public function test_invalid_entity_type_outputs_error_and_returns_failure(): void
    {
        $this->indexerPool->method('hasIndexer')->with('invalid_entity')->willReturn(false);

        $this->orchestrator->expects(self::never())
            ->method('reindex');

        $this->orchestrator->expects(self::never())
            ->method('reindexAll');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--entity' => 'invalid_entity']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('invalid_entity', $tester->getDisplay());
        self::assertStringContainsString('Unknown entity type', $tester->getDisplay());
    }

    public function test_exception_during_reindex_outputs_error_and_returns_failure(): void
    {
        $this->orchestrator->method('reindexAll')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Connection failed', $tester->getDisplay());
    }

    public function test_disabled_entity_type_is_skipped_with_a_message(): void
    {
        $this->indexerPool->method('hasIndexer')->with('customer')->willReturn(true);

        $this->orchestrator->expects(self::never())->method('reindex');
        $this->orchestrator->expects(self::never())->method('reindexAll');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--entity' => 'customer']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Skipping customer', $tester->getDisplay());
        self::assertStringContainsString('run_as_root_typesense/indexing/customer_enabled', $tester->getDisplay());
    }

    public function test_reindex_all_reports_the_disabled_entity_types(): void
    {
        $this->indexerPool->method('getAll')->willReturn([
            'product' => $this->createMock(EntityIndexerInterface::class),
            'customer' => $this->createMock(EntityIndexerInterface::class),
        ]);

        $this->orchestrator->expects(self::once())->method('reindexAll');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        self::assertStringContainsString('Skipping customer', $tester->getDisplay());
        self::assertStringNotContainsString('Skipping product', $tester->getDisplay());
    }
}
