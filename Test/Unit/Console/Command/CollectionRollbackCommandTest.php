<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Console\Command\CollectionRollbackCommand;
use RunAsRoot\TypeSense\Model\Collection\ZeroDowntimeService;
use Symfony\Component\Console\Tester\CommandTester;

final class CollectionRollbackCommandTest extends TestCase
{
    private ZeroDowntimeService&MockObject $service;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->service = $this->createMock(ZeroDowntimeService::class);
        $resolver = $this->createMock(CollectionNameResolverInterface::class);
        $resolver->method('resolve')->with('product', 'de', 3)->willReturn('rar_product_de');
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn('3');
        $storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $storeRepository->method('get')->with('de')->willReturn($store);

        $this->tester = new CommandTester(new CollectionRollbackCommand($this->service, $resolver, $storeRepository));
    }

    public function test_prints_alias_target_on_success(): void
    {
        $this->service->expects(self::once())->method('rollback')->with('product', 'de', 3)
            ->willReturn('rar_product_de_v2');

        $exitCode = $this->tester->execute(['--entity' => 'product', '--store' => 'de']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Alias rar_product_de now points to rar_product_de_v2', $this->tester->getDisplay());
    }

    public function test_fails_with_message_when_nothing_to_roll_back_to(): void
    {
        $this->service->method('rollback')->willThrowException(new \RuntimeException('No previous version to roll back to'));

        $exitCode = $this->tester->execute(['--entity' => 'product', '--store' => 'de']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No previous version to roll back to', $this->tester->getDisplay());
    }

    public function test_fails_when_required_options_are_missing(): void
    {
        $this->service->expects(self::never())->method('rollback');

        self::assertSame(1, $this->tester->execute(['--entity' => 'product']));
    }
}
