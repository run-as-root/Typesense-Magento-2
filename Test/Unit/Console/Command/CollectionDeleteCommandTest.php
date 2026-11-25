<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Console\Command\CollectionDeleteCommand;
use RunAsRoot\TypeSense\Model\Collection\EntityCollectionPurger;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;
use RunAsRoot\TypeSense\Model\Indexer\IndexableStoreResolver;
use Magento\Store\Api\Data\StoreInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Typesense\Exceptions\ObjectNotFound;

final class CollectionDeleteCommandTest extends TestCase
{
    private CollectionManagerInterface&MockObject $collectionManager;
    private EntityCollectionPurger&MockObject $purger;
    private IndexableStoreResolver&MockObject $storeResolver;
    private EntityIndexerPool&MockObject $indexerPool;
    private CollectionDeleteCommand $command;

    protected function setUp(): void
    {
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->purger = $this->createMock(EntityCollectionPurger::class);
        $this->storeResolver = $this->createMock(IndexableStoreResolver::class);
        $this->indexerPool = $this->createMock(EntityIndexerPool::class);
        $this->command = new CollectionDeleteCommand(
            $this->collectionManager,
            $this->purger,
            $this->storeResolver,
            $this->indexerPool,
        );
    }

    public function test_returns_failure_with_warning_when_force_option_is_not_provided(): void
    {
        $this->collectionManager->expects(self::never())->method('deleteCollection');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['name' => 'products_v1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('WARNING', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function test_deletes_collection_successfully_with_force_option(): void
    {
        $this->collectionManager->expects(self::once())
            ->method('deleteCollection')
            ->with('products_v1');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['name' => 'products_v1', '--force' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('products_v1', $tester->getDisplay());
        self::assertStringContainsString('deleted successfully', $tester->getDisplay());
    }

    public function test_handles_object_not_found_exception_gracefully(): void
    {
        $this->collectionManager->method('deleteCollection')
            ->willThrowException(new ObjectNotFound('not found'));

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['name' => 'missing_collection', '--force' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('missing_collection', $tester->getDisplay());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_handles_generic_exception_gracefully(): void
    {
        $this->collectionManager->method('deleteCollection')
            ->willThrowException(new \RuntimeException('Unexpected server error'));

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['name' => 'products_v1', '--force' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Unexpected server error', $tester->getDisplay());
    }

    public function test_entity_option_deletes_every_collection_of_a_disabled_entity_type(): void
    {
        $this->indexerPool->method('hasIndexer')->with('customer')->willReturn(true);
        $this->storeResolver->method('getStores')->with('customer')->willReturn([]);

        $this->purger->expects(self::once())
            ->method('purge')
            ->with('customer')
            ->willReturn(['prinz_customer_de (alias)', 'prinz_customer_de_v3']);
        $this->collectionManager->expects(self::never())->method('deleteCollection');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--entity' => 'customer', '--force' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('prinz_customer_de_v3', $tester->getDisplay());
    }

    public function test_entity_option_refuses_an_entity_type_that_is_still_indexed(): void
    {
        $this->indexerPool->method('hasIndexer')->willReturn(true);
        $this->storeResolver->method('getStores')->willReturn([$this->createMock(StoreInterface::class)]);

        $this->purger->expects(self::never())->method('purge');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--entity' => 'product', '--force' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('run_as_root_typesense/indexing/product_enabled', $tester->getDisplay());
    }

    public function test_entity_option_rejects_an_unknown_entity_type(): void
    {
        $this->indexerPool->method('hasIndexer')->willReturn(false);

        $this->purger->expects(self::never())->method('purge');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--entity' => 'nope', '--force' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Unknown entity type', $tester->getDisplay());
    }

    public function test_entity_option_requires_force(): void
    {
        $this->indexerPool->method('hasIndexer')->willReturn(true);

        $this->purger->expects(self::never())->method('purge');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--entity' => 'customer']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function test_requires_a_name_or_an_entity_type(): void
    {
        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(1, $exitCode);
    }
}
