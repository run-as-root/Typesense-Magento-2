<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Console\Command\SuggestionSeedCommand;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionCollectionProvisioner;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionPruner;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionSeeder;
use Symfony\Component\Console\Tester\CommandTester;

final class SuggestionSeedCommandTest extends TestCase
{
    private StoreRepositoryInterface&MockObject $storeRepository;
    private TypeSenseConfigInterface&MockObject $config;
    private SuggestionCollectionProvisioner&MockObject $provisioner;
    private SuggestionSeeder&MockObject $seeder;
    private SuggestionPruner&MockObject $pruner;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([
            $this->store(0, 'admin', true),
            $this->store(3, 'de', true),
            $this->store(4, 'at', true),
            $this->store(5, 'ch', false),
        ]);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isSuggestionIndexingEnabled')->willReturnCallback(
            static fn (?int $storeId): bool => $storeId !== 4,
        );
        $this->provisioner = $this->createMock(SuggestionCollectionProvisioner::class);
        $this->seeder = $this->createMock(SuggestionSeeder::class);
        $this->pruner = $this->createMock(SuggestionPruner::class);
        $this->pruner->method('prune')->willReturn(['checked' => 12, 'valid' => 9, 'invalid' => 2, 'deleted' => 1]);

        $this->tester = new CommandTester(new SuggestionSeedCommand(
            $this->storeRepository,
            $this->config,
            $this->provisioner,
            $this->seeder,
            $this->pruner,
        ));
    }

    public function test_seeds_every_active_store_with_suggestions_enabled(): void
    {
        $this->provisioner->expects(self::once())->method('ensure')->with(3, 'de');
        $this->seeder->expects(self::once())
            ->method('seed')
            ->with(3, 'de', null)
            ->willReturn(['total' => 12, 'imported' => 10, 'existing' => 2, 'failed' => 0]);
        $this->pruner->expects(self::once())->method('prune')->with(3, 'de');

        $exitCode = $this->tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString(
            'de: 10 imported, 2 already present, 0 failed; pruned: 9 shown, 2 without results, 1 deleted',
            $this->tester->getDisplay(),
        );
        self::assertStringContainsString('at: suggestions disabled, skipped', $this->tester->getDisplay());
    }

    public function test_store_and_limit_options_narrow_the_seed(): void
    {
        $this->storeRepository->method('get')->with('de')->willReturn($this->store(3, 'de', true));

        $this->seeder->expects(self::once())
            ->method('seed')
            ->with(3, 'de', 50)
            ->willReturn(['total' => 0, 'imported' => 0, 'existing' => 0, 'failed' => 0]);

        self::assertSame(0, $this->tester->execute(['--store' => 'de', '--limit' => '50']));
    }

    public function test_unknown_store_fails(): void
    {
        $this->storeRepository->method('get')->willThrowException(new NoSuchEntityException());

        $this->seeder->expects(self::never())->method('seed');

        self::assertSame(1, $this->tester->execute(['--store' => 'xx']));
        self::assertStringContainsString('Unknown store "xx"', $this->tester->getDisplay());
    }

    public function test_limit_must_be_a_positive_integer(): void
    {
        $this->seeder->expects(self::never())->method('seed');

        self::assertSame(1, $this->tester->execute(['--limit' => 'abc']));
        self::assertSame(1, $this->tester->execute(['--limit' => '0']));
    }

    public function test_a_failing_store_is_reported_and_the_others_still_seeded(): void
    {
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([$this->store(3, 'de', true), $this->store(6, 'nl', true)]);
        $this->tester = new CommandTester(new SuggestionSeedCommand(
            $this->storeRepository,
            $this->config,
            $this->provisioner,
            $this->seeder,
            $this->pruner,
        ));
        $this->provisioner->method('ensure')->willReturnCallback(static function (int $storeId): bool {
            if ($storeId === 3) {
                throw new \RuntimeException('Typesense unreachable');
            }

            return false;
        });

        $this->seeder->expects(self::once())
            ->method('seed')
            ->with(6, 'nl', null)
            ->willReturn(['total' => 0, 'imported' => 0, 'existing' => 0, 'failed' => 0]);

        self::assertSame(1, $this->tester->execute([]));
        self::assertStringContainsString('de: Typesense unreachable', $this->tester->getDisplay());
    }

    private function store(int $id, string $code, bool $active): StoreInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getIsActive')->willReturn($active);

        return $store;
    }
}
