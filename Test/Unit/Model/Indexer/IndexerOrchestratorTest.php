<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Model\Collection\CollectionNameResolver;
use RunAsRoot\TypeSense\Model\Collection\ZeroDowntimeService;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\BatchImportService;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

final class IndexerOrchestratorTest extends TestCase
{
    private EntityIndexerInterface&MockObject $indexer;
    private ZeroDowntimeService&MockObject $zeroDowntimeService;
    private BatchImportService&MockObject $batchImportService;
    private CollectionNameResolver&MockObject $nameResolver;
    private TypeSenseConfigInterface&MockObject $config;
    private StoreRepositoryInterface&MockObject $storeRepository;
    private AliasManagerInterface&MockObject $aliasManager;
    private CollectionManagerInterface&MockObject $collectionManager;
    private LoggerInterface&MockObject $logger;
    private IndexerOrchestrator $sut;

    protected function setUp(): void
    {
        $this->indexer = $this->createMock(EntityIndexerInterface::class);
        $this->indexer->method('getSchemaFields')->willReturn([['name' => 'title', 'type' => 'string']]);
        $this->indexer->method('buildDocuments')->willReturn([['id' => '5']]);

        $this->zeroDowntimeService = $this->createMock(ZeroDowntimeService::class);
        $this->batchImportService = $this->createMock(BatchImportService::class);
        $this->nameResolver = $this->createMock(CollectionNameResolver::class);
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');

        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $store->method('getCode')->willReturn('de');
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([$store]);

        $this->aliasManager = $this->createMock(AliasManagerInterface::class);
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new IndexerOrchestrator(
            new EntityIndexerPool(['product' => $this->indexer]),
            $this->zeroDowntimeService,
            $this->batchImportService,
            $this->nameResolver,
            $this->config,
            $this->storeRepository,
            $this->aliasManager,
            $this->collectionManager,
            $this->logger,
        );
    }

    public function test_partial_reindex_imports_into_alias_without_swapping(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->aliasManager->method('aliasExists')->with('rar_product_de')->willReturn(true);

        $this->zeroDowntimeService->expects(self::never())->method('startReindex');
        $this->zeroDowntimeService->expects(self::never())->method('finishReindex');
        $this->batchImportService->expects(self::once())
            ->method('import')
            ->with('rar_product_de', self::anything(), 3)
            ->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->sut->reindex('product', [5]);
    }

    public function test_partial_reindex_skips_store_when_alias_missing(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->aliasManager->method('aliasExists')->willReturn(false);
        $this->collectionManager->method('collectionExists')->willReturn(false);

        $this->zeroDowntimeService->expects(self::never())->method('startReindex');
        $this->batchImportService->expects(self::never())->method('import');
        $messages = [];
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('info')->willReturnCallback(
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );
        $this->sut = $this->buildSut();

        $this->sut->reindex('product', [5]);

        $skipMessages = array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'full reindex required'),
        );
        self::assertNotEmpty($skipMessages);
    }

    public function test_full_reindex_uses_versioned_collection_and_swaps(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);

        $this->zeroDowntimeService->expects(self::once())
            ->method('startReindex')
            ->willReturn('rar_product_de_v2');
        $this->batchImportService->expects(self::once())
            ->method('import')
            ->with('rar_product_de_v2', self::anything(), 3)
            ->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $this->zeroDowntimeService->expects(self::once())
            ->method('finishReindex')
            ->with('product', 'de', 'rar_product_de_v2', 3);

        $this->sut->reindex('product');
    }

    public function test_full_reindex_without_zero_downtime_creates_missing_collection(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->collectionManager->method('collectionExists')->with('rar_product_de')->willReturn(false);

        $this->collectionManager->expects(self::once())
            ->method('createCollection')
            ->with('rar_product_de', [
                'name' => 'rar_product_de',
                'fields' => [['name' => 'title', 'type' => 'string']],
            ]);
        $this->zeroDowntimeService->expects(self::never())->method('startReindex');
        $this->batchImportService->expects(self::once())
            ->method('import')
            ->with('rar_product_de', self::anything(), 3)
            ->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->sut->reindex('product');
    }

    private function buildSut(): IndexerOrchestrator
    {
        return new IndexerOrchestrator(
            new EntityIndexerPool(['product' => $this->indexer]),
            $this->zeroDowntimeService,
            $this->batchImportService,
            $this->nameResolver,
            $this->config,
            $this->storeRepository,
            $this->aliasManager,
            $this->collectionManager,
            $this->logger,
        );
    }
}
