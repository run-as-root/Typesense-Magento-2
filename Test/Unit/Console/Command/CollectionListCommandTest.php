<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Console\Command\CollectionListCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class CollectionListCommandTest extends TestCase
{
    private CollectionManagerInterface&MockObject $collectionManager;
    private AliasManagerInterface&MockObject $aliasManager;
    private CollectionListCommand $command;

    protected function setUp(): void
    {
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->aliasManager = $this->createMock(AliasManagerInterface::class);
        $this->command = new CollectionListCommand($this->collectionManager, $this->aliasManager);
    }

    public function test_lists_collections_with_document_count_and_alias_in_table_format(): void
    {
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'products_v1', 'num_documents' => 42],
            ['name' => 'categories_v1', 'num_documents' => 10],
        ]);

        $this->aliasManager->method('listAliases')->willReturn([
            ['name' => 'products', 'collection_name' => 'products_v1'],
        ]);

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('products_v1', $output);
        self::assertStringContainsString('42', $output);
        self::assertStringContainsString('products', $output);
        self::assertStringContainsString('categories_v1', $output);
        self::assertStringContainsString('10', $output);
        self::assertStringContainsString('Total collections: 2', $output);
    }

    public function test_shows_empty_table_when_no_collections_exist(): void
    {
        $this->collectionManager->method('listCollections')->willReturn([]);
        $this->aliasManager->method('listAliases')->willReturn([]);

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Total collections: 0', $tester->getDisplay());
    }

    public function test_handles_exception_gracefully_and_returns_failure(): void
    {
        $this->collectionManager->method('listCollections')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Connection refused', $tester->getDisplay());
    }
}
