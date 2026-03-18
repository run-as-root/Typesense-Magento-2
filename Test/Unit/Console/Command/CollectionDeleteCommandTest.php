<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Console\Command\CollectionDeleteCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Typesense\Exceptions\ObjectNotFound;

final class CollectionDeleteCommandTest extends TestCase
{
    private CollectionManagerInterface&MockObject $collectionManager;
    private CollectionDeleteCommand $command;

    protected function setUp(): void
    {
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->command = new CollectionDeleteCommand($this->collectionManager);
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
}
