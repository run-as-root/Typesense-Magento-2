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
use RunAsRoot\TypeSense\Api\CollectionSettingsProviderInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Api\SelfMaintainedEntityInterface;
use RunAsRoot\TypeSense\Model\Collection\ApplierFailedException;
use RunAsRoot\TypeSense\Model\Collection\CollectionNameResolver;
use RunAsRoot\TypeSense\Model\Collection\ZeroDowntimeService;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\BatchImportService;
use RunAsRoot\TypeSense\Model\Indexer\DocumentDeleter;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;
use RunAsRoot\TypeSense\Model\Indexer\IndexableStoreResolver;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;
use RunAsRoot\TypeSense\Model\Indexer\SchemaChangeHandler;

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
    private SchemaChangeHandler&MockObject $schemaChangeHandler;
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
        $this->config->method('isEntityIndexingEnabled')->willReturn(true);

        $store = $this->createStore(3, 'de', true);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([$store]);

        $this->aliasManager = $this->createMock(AliasManagerInterface::class);
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->documentDeleter = $this->createMock(DocumentDeleter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->emulation = $this->createMock(Emulation::class);
        $this->schemaChangeHandler = $this->createMock(SchemaChangeHandler::class);

        $this->sut = new IndexerOrchestrator(
            new EntityIndexerPool(['product' => $this->indexer]),
            $this->zeroDowntimeService,
            $this->batchImportService,
            $this->nameResolver,
            $this->config,
            new IndexableStoreResolver($this->storeRepository, $this->config, $this->logger),
            $this->aliasManager,
            $this->collectionManager,
            $this->documentDeleter,
            $this->logger,
            $this->emulation,
            $this->schemaChangeHandler,
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

    public function test_failed_zero_downtime_build_deletes_new_collection_and_rethrows(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_product_de_v2');
        $this->batchImportService->method('import')->willThrowException(new \RuntimeException('import failed'));

        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_product_de_v2');
        $this->zeroDowntimeService->expects(self::never())->method('finishReindex');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('import failed');

        $this->sut->reindex('product');
    }

    public function test_failed_appliers_delete_the_new_collection_and_rethrow(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_product_de_v2');
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $this->zeroDowntimeService->method('finishReindex')
            ->willThrowException(new ApplierFailedException('synonyms: typesense down'));

        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_product_de_v2');

        $this->expectException(ApplierFailedException::class);

        $this->sut->reindex('product');
    }

    public function test_failure_after_the_swap_keeps_the_new_collection(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_product_de_v2');
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $this->zeroDowntimeService->method('finishReindex')->willThrowException(new \RuntimeException('cleanup failed'));

        $this->collectionManager->expects(self::never())->method('deleteCollection');

        $this->expectException(\RuntimeException::class);

        $this->sut->reindex('product');
    }

    public function test_failed_orphan_cleanup_still_rethrows_build_error(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_product_de_v2');
        $this->batchImportService->method('import')->willThrowException(new \RuntimeException('import failed'));
        $this->collectionManager->method('deleteCollection')->willThrowException(new \RuntimeException('typesense down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('import failed');

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

    public function test_full_reindex_creates_collection_with_indexer_collection_settings(): void
    {
        $this->useIndexerWithCollectionSettings();
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->collectionManager->method('collectionExists')->willReturn(false);
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->collectionManager->expects(self::once())
            ->method('createCollection')
            ->with('rar_product_de', [
                'name' => 'rar_product_de',
                'fields' => [['name' => 'children', 'type' => 'object[]']],
                'enable_nested_fields' => true,
            ]);

        $this->sut->reindex('product');
    }

    public function test_zero_downtime_reindex_builds_collection_with_indexer_collection_settings(): void
    {
        $this->useIndexerWithCollectionSettings();
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->zeroDowntimeService->expects(self::once())
            ->method('startReindex')
            ->with('product', 'de', [
                'fields' => [['name' => 'children', 'type' => 'object[]']],
                'enable_nested_fields' => true,
            ], 3)
            ->willReturn('rar_product_de_v2');

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
        $this->config->method('isEntityIndexingEnabled')->willReturn(true);
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->sut = $this->buildSut();

        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->batchImportService->expects(self::once())
            ->method('import')
            ->with('rar_product_de', self::anything(), 3)
            ->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->sut->reindex('product');
    }

    public function test_reindex_indexes_store_enabled_when_default_scope_is_disabled(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturnCallback(
            static fn (?int $storeId = null): bool => $storeId === 3,
        );
        $this->config->method('isEntityIndexingEnabled')->willReturn(true);
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->sut = $this->buildSut();

        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->batchImportService->expects(self::once())
            ->method('import')
            ->with('rar_product_de', self::anything(), 3)
            ->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->sut->reindex('product');
    }

    public function test_reindex_all_reindexes_every_registered_entity_type(): void
    {
        $category = $this->createMock(EntityIndexerInterface::class);
        $category->method('getSchemaFields')->willReturn([]);
        $category->method('buildDocuments')->willReturn([]);
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $sut = new IndexerOrchestrator(
            new EntityIndexerPool(['product' => $this->indexer, 'category' => $category]),
            $this->zeroDowntimeService,
            $this->batchImportService,
            $this->nameResolver,
            $this->config,
            new IndexableStoreResolver($this->storeRepository, $this->config, $this->logger),
            $this->aliasManager,
            $this->collectionManager,
            $this->documentDeleter,
            $this->logger,
            $this->emulation,
            $this->schemaChangeHandler,
        );

        $this->indexer->expects(self::once())->method('buildDocuments');
        $category->expects(self::once())->method('buildDocuments');
        $this->batchImportService->method('import')->willReturn(['success' => 0, 'failed' => 0, 'total' => 0]);

        $sut->reindexAll();
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

    public function test_full_reindex_of_self_maintained_entity_only_provisions_it(): void
    {
        $indexer = $this->useSelfMaintainedIndexer();
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);

        $indexer->expects(self::once())->method('provision')->with(3, 'de');
        $indexer->expects(self::never())->method('buildDocuments');
        $this->zeroDowntimeService->expects(self::never())->method('startReindex');
        $this->zeroDowntimeService->expects(self::never())->method('finishReindex');
        $this->collectionManager->expects(self::never())->method('createCollection');
        $this->collectionManager->expects(self::never())->method('deleteCollection');
        $this->batchImportService->expects(self::never())->method('import');

        $this->sut->reindex('product');
    }

    public function test_partial_reindex_of_self_maintained_entity_does_nothing(): void
    {
        $indexer = $this->useSelfMaintainedIndexer();

        $indexer->expects(self::never())->method('provision');
        $indexer->expects(self::never())->method('buildDocuments');
        $this->batchImportService->expects(self::never())->method('import');
        $this->documentDeleter->expects(self::never())->method('deleteByIds');

        $this->sut->reindex('product', [5]);
    }

    private function useSelfMaintainedIndexer(): EntityIndexerInterface&SelfMaintainedEntityInterface&MockObject
    {
        $indexer = $this->createMockForIntersectionOfInterfaces([
            EntityIndexerInterface::class,
            SelfMaintainedEntityInterface::class,
        ]);
        $this->indexer = $indexer;
        $this->sut = $this->buildSut();

        return $indexer;
    }

    private function useIndexerWithCollectionSettings(): void
    {
        $indexer = $this->createMockForIntersectionOfInterfaces([
            EntityIndexerInterface::class,
            CollectionSettingsProviderInterface::class,
        ]);
        $indexer->method('getSchemaFields')->willReturn([['name' => 'children', 'type' => 'object[]']]);
        $indexer->method('getCollectionSettings')->willReturn(['enable_nested_fields' => true]);
        $indexer->method('buildDocuments')->willReturn([['id' => '5']]);
        $this->indexer = $indexer;
        $this->sut = $this->buildSut();
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
            new IndexableStoreResolver($this->storeRepository, $this->config, $this->logger),
            $this->aliasManager,
            $this->collectionManager,
            $this->documentDeleter,
            $this->logger,
            $this->emulation,
            $this->schemaChangeHandler,
        );
    }

    public function test_full_reindex_skips_entity_type_disabled_for_the_store(): void
    {
        $this->useConfigWithEntityIndexingDisabled();

        $this->indexer->expects(self::never())->method('buildDocuments');
        $this->zeroDowntimeService->expects(self::never())->method('startReindex');
        $this->collectionManager->expects(self::never())->method('createCollection');
        $this->batchImportService->expects(self::never())->method('import');

        $this->sut->reindex('product');
    }

    public function test_partial_reindex_skips_entity_type_disabled_for_the_store(): void
    {
        $this->useConfigWithEntityIndexingDisabled();

        $this->indexer->expects(self::never())->method('buildDocuments');
        $this->batchImportService->expects(self::never())->method('import');
        $this->documentDeleter->expects(self::never())->method('deleteByIds');

        $this->sut->reindex('product', [5]);
    }

    public function test_reindex_all_skips_disabled_entity_types(): void
    {
        $customer = $this->createMock(EntityIndexerInterface::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->config->method('isEntityIndexingEnabled')->willReturnCallback(
            static fn (string $entityType, ?int $storeId = null): bool => $entityType !== 'customer',
        );
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $sut = new IndexerOrchestrator(
            new EntityIndexerPool(['product' => $this->indexer, 'customer' => $customer]),
            $this->zeroDowntimeService,
            $this->batchImportService,
            $this->nameResolver,
            $this->config,
            new IndexableStoreResolver($this->storeRepository, $this->config, $this->logger),
            $this->aliasManager,
            $this->collectionManager,
            $this->documentDeleter,
            $this->logger,
            $this->emulation,
            $this->schemaChangeHandler,
        );

        $this->indexer->expects(self::once())->method('buildDocuments');
        $customer->expects(self::never())->method('buildDocuments');
        $customer->expects(self::never())->method('getSchemaFields');

        $sut->reindexAll();
    }

    private function useConfigWithEntityIndexingDisabled(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->config->method('isEntityIndexingEnabled')->with('product', 3)->willReturn(false);
        $this->aliasManager->method('aliasExists')->willReturn(true);
        $this->sut = $this->buildSut();
    }

    public function test_schema_change_of_a_zero_downtime_build_is_handled_once_after_all_stores(): void
    {
        $this->useTwoStores();
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_product_v2');
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $this->zeroDowntimeService->method('finishReindex')->willReturnCallback(
            static fn (string $entityType, string $storeCode): bool => $storeCode === 'at',
        );

        $this->schemaChangeHandler->expects(self::once())->method('handle')->with(['rar_product_at']);

        $this->sut->reindex('product');
    }

    public function test_unchanged_schema_is_not_handled(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_product_de_v2');
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $this->zeroDowntimeService->method('finishReindex')->willReturn(false);

        $this->schemaChangeHandler->expects(self::once())->method('handle')->with([]);

        $this->sut->reindex('product');
    }

    public function test_collection_created_without_zero_downtime_counts_as_a_schema_change(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(false);
        $this->collectionManager->method('collectionExists')->willReturn(false);
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->schemaChangeHandler->expects(self::once())->method('handle')->with(['rar_product_de']);

        $this->sut->reindex('product');
    }

    public function test_schema_change_of_an_earlier_store_is_handled_when_a_later_store_fails(): void
    {
        $this->useTwoStores();
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_product_v2');
        $this->batchImportService->method('import')->willReturnOnConsecutiveCalls(
            ['success' => 1, 'failed' => 0, 'total' => 1],
            self::throwException(new \RuntimeException('import failed')),
        );
        $this->zeroDowntimeService->method('finishReindex')->willReturn(true);

        $this->schemaChangeHandler->expects(self::once())->method('handle')->with(['rar_product_de']);
        $this->expectException(\RuntimeException::class);

        $this->sut->reindex('product');
    }

    public function test_failed_appliers_of_one_store_do_not_stop_the_other_stores(): void
    {
        $this->useTwoStores();
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturnCallback(
            static fn (string $entityType, string $storeCode): string => "rar_product_{$storeCode}_v2",
        );
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $this->zeroDowntimeService->expects(self::exactly(2))->method('finishReindex')->willReturnCallback(
            static fn (string $entityType, string $storeCode): bool => $storeCode === 'de'
                ? throw new ApplierFailedException('synonyms: typesense down')
                : true,
        );

        $this->schemaChangeHandler->expects(self::once())->method('handle')->with(['rar_product_at']);
        $this->expectException(ApplierFailedException::class);
        $this->expectExceptionMessage('synonyms: typesense down');

        $this->sut->reindex('product');
    }

    public function test_failed_appliers_of_one_entity_type_do_not_stop_the_other_types_in_a_full_run(): void
    {
        $category = $this->createMock(EntityIndexerInterface::class);
        $category->method('getSchemaFields')->willReturn([]);
        $category->method('buildDocuments')->willReturn([]);
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->zeroDowntimeService->method('startReindex')->willReturn('rar_v2');
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);
        $this->zeroDowntimeService->method('finishReindex')->willReturnCallback(
            static fn (string $entityType): bool => $entityType === 'product'
                ? throw new ApplierFailedException('synonyms: typesense down')
                : false,
        );
        $sut = new IndexerOrchestrator(
            new EntityIndexerPool(['product' => $this->indexer, 'category' => $category]),
            $this->zeroDowntimeService,
            $this->batchImportService,
            $this->nameResolver,
            $this->config,
            new IndexableStoreResolver($this->storeRepository, $this->config, $this->logger),
            $this->aliasManager,
            $this->collectionManager,
            $this->documentDeleter,
            $this->logger,
            $this->emulation,
            $this->schemaChangeHandler,
        );

        $category->expects(self::once())->method('buildDocuments');
        $this->expectException(ApplierFailedException::class);

        $sut->reindexAll();
    }

    public function test_partial_reindex_never_handles_a_schema_change(): void
    {
        $this->config->method('isZeroDowntimeEnabled')->willReturn(true);
        $this->aliasManager->method('aliasExists')->willReturn(true);
        $this->batchImportService->method('import')->willReturn(['success' => 1, 'failed' => 0, 'total' => 1]);

        $this->schemaChangeHandler->expects(self::never())->method('handle');

        $this->sut->reindex('product', [5]);
    }

    private function useTwoStores(): void
    {
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeRepository->method('getList')->willReturn([
            $this->createStore(3, 'de', true),
            $this->createStore(4, 'at', true),
        ]);
        $this->nameResolver = $this->createMock(CollectionNameResolver::class);
        $this->nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entityType, string $storeCode): string => "rar_{$entityType}_{$storeCode}",
        );
        $this->sut = $this->buildSut();
    }
}
