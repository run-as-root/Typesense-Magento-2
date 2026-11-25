<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Cron;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Cron\SuggestionPruneCron;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionPruner;

final class SuggestionPruneCronTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private CollectionManagerInterface&MockObject $collectionManager;
    private SuggestionPruner&MockObject $pruner;
    private LoggerInterface&MockObject $logger;
    private SuggestionPruneCron $sut;

    protected function setUp(): void
    {
        $storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $storeRepository->method('getList')->willReturn([
            $this->store(0, 'admin', true),
            $this->store(3, 'de', true),
            $this->store(4, 'at', true),
            $this->store(5, 'ch', false),
            $this->store(6, 'nl', true),
        ]);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isSuggestionIndexingEnabled')->willReturnCallback(
            static fn (?int $storeId): bool => $storeId !== 4,
        );
        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entityType, string $storeCode): string => "rar_{$entityType}_{$storeCode}",
        );
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->collectionManager->method('collectionExists')->willReturnCallback(
            static fn (string $name): bool => $name !== 'rar_suggestion_nl',
        );
        $this->pruner = $this->createMock(SuggestionPruner::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new SuggestionPruneCron(
            $storeRepository,
            $this->config,
            $nameResolver,
            $this->collectionManager,
            $this->pruner,
            $this->logger,
        );
    }

    public function test_prunes_each_active_store_with_a_suggestion_collection(): void
    {
        $this->pruner->expects(self::once())->method('prune')->with(3, 'de');

        $this->sut->execute();
    }

    public function test_a_failing_store_is_logged_and_does_not_stop_the_others(): void
    {
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $storeRepository->method('getList')->willReturn([$this->store(3, 'de', true), $this->store(6, 'nl', true)]);
        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturn('rar_suggestion_x');
        $sut = new SuggestionPruneCron(
            $storeRepository,
            $this->config,
            $nameResolver,
            $this->collectionManager,
            $this->pruner,
            $this->logger,
        );
        $pruned = [];
        $this->pruner->method('prune')->willReturnCallback(static function (int $storeId) use (&$pruned): array {
            $pruned[] = $storeId;
            if ($storeId === 3) {
                throw new \RuntimeException('Typesense unreachable');
            }

            return ['checked' => 0, 'valid' => 0, 'invalid' => 0, 'deleted' => 0];
        });

        $this->logger->expects(self::once())->method('error')->with(self::stringContains('Typesense unreachable'));

        $sut->execute();

        self::assertSame([3, 6], $pruned);
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
