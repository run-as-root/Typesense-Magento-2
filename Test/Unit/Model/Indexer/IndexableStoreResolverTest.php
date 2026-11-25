<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexableStoreResolver;

final class IndexableStoreResolverTest extends TestCase
{
    private StoreRepositoryInterface&MockObject $storeRepository;
    private TypeSenseConfigInterface&MockObject $config;
    private IndexableStoreResolver $sut;

    protected function setUp(): void
    {
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([
            $this->createStore(0, 'admin', true),
            $this->createStore(3, 'de', true),
            $this->createStore(4, 'fr', false),
            $this->createStore(5, 'nl', true),
            $this->createStore(6, 'at', true),
        ]);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturnCallback(static fn (?int $storeId = null): bool => $storeId !== 5);
        $this->sut = new IndexableStoreResolver($this->storeRepository, $this->config, $this->createMock(LoggerInterface::class));
    }

    public function test_returns_active_enabled_stores_with_the_entity_type_enabled(): void
    {
        $this->config->method('isEntityIndexingEnabled')->willReturnCallback(
            static fn (string $entityType, ?int $storeId = null): bool => $storeId !== 6,
        );

        $codes = array_map(static fn (StoreInterface $store): string => $store->getCode(), $this->sut->getStores('product'));

        self::assertSame(['de'], $codes);
    }

    public function test_returns_no_store_when_the_entity_type_is_disabled_everywhere(): void
    {
        $this->config->method('isEntityIndexingEnabled')->willReturn(false);

        self::assertSame([], $this->sut->getStores('customer'));
    }

    public function test_entity_type_flag_is_read_per_store(): void
    {
        $this->config->expects(self::exactly(2))
            ->method('isEntityIndexingEnabled')
            ->willReturnCallback(static fn (string $entityType, ?int $storeId = null): bool => $entityType === 'order');

        $this->sut->getStores('order');
    }

    private function createStore(int $id, string $code, bool $active): StoreInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getIsActive')->willReturn($active);

        return $store;
    }
}
