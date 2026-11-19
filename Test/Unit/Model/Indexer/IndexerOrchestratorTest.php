<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer;

use Magento\Framework\App\Area;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\App\Emulation;
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
use RunAsRoot\TypeSense\Model\Indexer\DocumentDeleter;
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
    private DocumentDeleter&MockObject $documentDeleter;
    private LoggerInterface&MockObject $logger;
    private Emulation&MockObject $emulation;
    private IndexerOrchestrator $sut;

    protected function setUp(): void
    {
        $this->indexer = $this->createMock(EntityIndexerInterface::class);
        $this->indexer->method('getSchemaFields')->willReturn([['name' => 'title', 'type' => 'string']]);
        $this->indexer->method('buildDocuments')->willReturn([['id' => '5']]);
        $this->indexer->method('getDocumentId')->willReturnCallback(static fn (int $id): string => (string) $id);

        $this->zeroDowntimeService = $this->createMock(ZeroDowntimeService::class);
        $this->batchImportService = $this->createMock(BatchImportService::class);
        $this->nameResolver = $this->createMock(CollectionNameResolver::class);
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');

        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);

        $store = $this->createStore(3, 'de', true);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([$store]);

        $this->aliasManager = $this->createMock(AliasManagerInterface::class);
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->documentDeleter = $this->createMock(DocumentDeleter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->emulation = $this->createMock(Emulation::class);

        $this->sut = new IndexerOrchestrator(
            new EntityIndexerPool(['product' => $this->indexer]),
            $this->zeroDowntimeService,
            $this->batchImportService,
            $this->nameResolver,
            $this->config,
            $this->storeRepository,
            $this->aliasManager,
            $this->collectionManager,
            $this->documentDeleter,
            $this->logger,
            $this->emulation,
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

    public function test_partial_reindex_deletes_ids_not_yielded_by_indexer(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->aliasManager->method('aliasExists')->with('rar_product_de')->willReturn(true);
        $this->batchImportService->method('import')->willReturnCallback(
            static function (string $name, iterable $documents): array {
                foreach ($documents as $document) {
                    unset($document);
                }
                return ['success' => 1, 'failed' => 0, 'total' => 1];
            },
        );

        $this->documentDeleter->expects(self::once())
            ->method('deleteByIds')
            ->with('rar_product_de', ['6', '7'], 3);

        $this->sut->reindex('product', [5, 6, 7]);
    }

    public function test_partial_reindex_does_not_delete_when_all_ids_yielded(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->aliasManager->method('aliasExists')->willReturn(true);
        $this->batchImportService->method('import')->willReturnCallback(
            static function (string $name, iterable $documents): array {
                foreach ($documents as $document) {
                    unset($document);
                }
                return ['success' => 1, 'failed' => 0, 'total' => 1];
            },
        );

        $this->documentDeleter->expects(self::never())->method('deleteByIds');

        $this->sut->reindex('product', [5]);
    }

    public function test_partial_reindex_maps_entity_ids_to_document_ids_before_diffing(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->aliasManager->method('aliasExists')->willReturn(true);
        $this->indexer = $this->createMock(EntityIndexerInterface::class);
        $this->indexer->method('getDocumentId')
            ->willReturnCallback(static fn (int $id): string => 'customer_' . $id);
        $this->indexer->method('buildDocuments')->willReturn([['id' => 'customer_5']]);
        $this->batchImportService->method('import')->willReturnCallback(
            static function (string $name, iterable $documents): array {
                foreach ($documents as $document) {
                    unset($document);
                }
                return ['success' => 1, 'failed' => 0, 'total' => 1];
            },
        );
        $this->sut = $this->buildSut();

        $this->documentDeleter->expects(self::once())
            ->method('deleteByIds')
            ->with('rar_product_de', ['customer_6'], 3);

        $this->sut->reindex('product', [5, 6]);
    }

    public function test_full_reindex_never_deletes_documents(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->documentDeleter->expects(self::never())->method('deleteByIds');

        $this->sut->reindex('product');
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

    public function test_reindex_skips_inactive_and_disabled_stores(): void
    {
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([
            $this->createStore(0, 'admin', true),
            $this->createStore(3, 'de', true),
            $this->createStore(4, 'fr', false),
            $this->createStore(5, 'nl', true),
        ]);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturnCallback(
            static fn (?int $storeId = null): bool => $storeId !== 5,
        );
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->sut = $this->buildSut();

        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->batchImportService->expects(self::once())
            ->method('import')
            ->with('rar_product_de', self::anything(), 3)
            ->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->sut->reindex('product');
    }

    public function test_reindex_emulates_store_and_stops_on_exception(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->emulation->expects(self::once())
            ->method('startEnvironmentEmulation')
            ->with(3, Area::AREA_FRONTEND, true);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');
        $this->batchImportService->method('import')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);

        $this->sut->reindex('product');
    }

    public function test_partial_reindex_deletes_product_removed_from_store_website(): void
    {
        $store = $this->createStore(8, 'nl', true);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([$store]);
        $this->nameResolver = $this->createMock(CollectionNameResolver::class);
        $this->nameResolver->method('resolve')->willReturn('rar_product_nl');
        $this->indexer = $this->createMock(EntityIndexerInterface::class);
        $this->indexer->method('buildDocuments')->willReturn([]);
        $this->indexer->method('getDocumentId')->willReturnCallback(static fn (int $id): string => (string) $id);
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->aliasManager->method('aliasExists')->willReturn(true);
        $this->batchImportService->method('import')->willReturnCallback(
            static function (string $name, iterable $documents): array {
                foreach ($documents as $document) {
                    unset($document);
                }
                return ['success' => 0, 'failed' => 0, 'total' => 0];
            },
        );
        $this->sut = $this->buildSut();

        $this->documentDeleter->expects(self::once())
            ->method('deleteByIds')
            ->with('rar_product_nl', ['9'], 8);

        $this->sut->reindex('product', [9]);
    }

    private function createStore(int $id, string $code, bool $active): StoreInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getIsActive')->willReturn($active);

        return $store;
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
            $this->documentDeleter,
            $this->logger,
            $this->emulation,
        );
    }
}
