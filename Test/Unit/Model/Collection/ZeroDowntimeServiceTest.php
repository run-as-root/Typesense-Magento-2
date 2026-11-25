<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Collection;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\PostSwapApplierInterface;
use RunAsRoot\TypeSense\Model\Collection\ApplierFailedException;
use RunAsRoot\TypeSense\Model\Collection\ZeroDowntimeService;
use Typesense\Exceptions\ObjectNotFound;

final class ZeroDowntimeServiceTest extends TestCase
{
    private CollectionManagerInterface&MockObject $collectionManager;
    private AliasManagerInterface&MockObject $aliasManager;
    private CollectionNameResolverInterface&MockObject $nameResolver;
    private LoggerInterface&MockObject $logger;
    private PostSwapApplierInterface&MockObject $applier;
    private ZeroDowntimeService $sut;

    protected function setUp(): void
    {
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->aliasManager = $this->createMock(AliasManagerInterface::class);
        $this->nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->applier = $this->createMock(PostSwapApplierInterface::class);

        $this->sut = new ZeroDowntimeService(
            $this->collectionManager,
            $this->aliasManager,
            $this->nameResolver,
            $this->logger,
            ['synonyms' => $this->applier],
        );
    }

    public function test_start_reindex_creates_versioned_collection_v1_when_no_alias_exists(): void
    {
        $this->nameResolver->method('resolve')
            ->with('products', 'default', null)
            ->willReturn('rar_products_default');

        $this->nameResolver->method('resolveVersioned')
            ->with('products', 'default', 1, null)
            ->willReturn('rar_products_default_v1');

        $this->aliasManager->method('getAlias')
            ->with('rar_products_default')
            ->willThrowException(new ObjectNotFound());

        $this->collectionManager->expects(self::once())
            ->method('createCollection')
            ->with('rar_products_default_v1', self::anything());

        $result = $this->sut->startReindex('products', 'default', []);

        self::assertSame('rar_products_default_v1', $result);
    }

    public function test_start_reindex_increments_version_when_alias_exists(): void
    {
        $this->nameResolver->method('resolve')
            ->with('products', 'default', null)
            ->willReturn('rar_products_default');

        $this->nameResolver->method('resolveVersioned')
            ->with('products', 'default', 3, null)
            ->willReturn('rar_products_default_v3');

        $this->aliasManager->method('getAlias')
            ->with('rar_products_default')
            ->willReturn(['collection_name' => 'rar_products_default_v2']);

        $this->collectionManager->expects(self::once())
            ->method('createCollection')
            ->with('rar_products_default_v3', self::anything());

        $result = $this->sut->startReindex('products', 'default', []);

        self::assertSame('rar_products_default_v3', $result);
    }

    public function test_finish_reindex_swaps_alias_and_cleans_up_old_versions(): void
    {
        $this->nameResolver->method('resolve')
            ->with('products', 'default', null)
            ->willReturn('rar_products_default');

        $this->aliasManager->method('getAlias')
            ->with('rar_products_default')
            ->willReturn(['collection_name' => 'rar_products_default_v2']);
        $this->aliasManager->expects(self::once())
            ->method('upsertAlias')
            ->with('rar_products_default', 'rar_products_default_v3');

        $this->collectionManager->method('listCollections')
            ->willReturn([
                ['name' => 'rar_products_default_v1'],
                ['name' => 'rar_products_default_v2'],
                ['name' => 'rar_products_default_v3'],
                ['name' => 'rar_categories_default_v1'],
            ]);

        $this->collectionManager->expects(self::once())
            ->method('deleteCollection')
            ->with('rar_products_default_v1');

        $this->sut->finishReindex('products', 'default', 'rar_products_default_v3');
    }

    public function test_cleanup_keeps_current_and_previous_version(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v2']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_p_v1'], ['name' => 'rar_p_v2'], ['name' => 'rar_p_v3'],
        ]);
        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_p_v1');

        $this->sut->finishReindex('p', 'de', 'rar_p_v3');
    }

    public function test_cleanup_compares_versions_numerically(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v10']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_p_v9'], ['name' => 'rar_p_v10'], ['name' => 'rar_p_v11'], ['name' => 'rar_p_vx'],
        ]);
        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_p_v9');

        $this->sut->finishReindex('p', 'de', 'rar_p_v11');
    }

    public function test_finish_migrates_plain_collection_with_alias_name(): void
    {
        $calls = [];
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->collectionManager->method('listCollections')->willReturn([]);
        $this->collectionManager->method('collectionExists')->with('rar_p')->willReturn(true);
        $this->aliasManager->method('aliasExists')->with('rar_p')->willReturn(false);
        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_p')
            ->willReturnCallback(static function () use (&$calls): array {
                $calls[] = 'delete';

                return [];
            });
        $this->aliasManager->expects(self::once())->method('upsertAlias')->with('rar_p', 'rar_p_v1')
            ->willReturnCallback(static function () use (&$calls): array {
                $calls[] = 'upsert';

                return [];
            });

        $this->sut->finishReindex('p', 'de', 'rar_p_v1');

        self::assertSame(['delete', 'upsert'], $calls);
    }

    public function test_finish_does_not_delete_collection_when_name_is_an_alias(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->collectionManager->method('listCollections')->willReturn([]);
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->aliasManager->method('aliasExists')->willReturn(true);
        $this->collectionManager->expects(self::never())->method('deleteCollection');

        $this->sut->finishReindex('p', 'de', 'rar_p_v2');
    }

    public function test_rollback_points_alias_to_previous_version(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v10']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_p_v9'], ['name' => 'rar_p_v10'],
        ]);
        $this->aliasManager->expects(self::once())->method('upsertAlias')->with('rar_p', 'rar_p_v9');

        self::assertSame('rar_p_v9', $this->sut->rollback('p', 'de'));
    }

    public function test_rollback_without_previous_version_throws(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v1']);
        $this->collectionManager->method('listCollections')->willReturn([['name' => 'rar_p_v1']]);
        $this->aliasManager->expects(self::never())->method('upsertAlias');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No previous version to roll back to');

        $this->sut->rollback('p', 'de');
    }

    public function test_start_reindex_after_rollback_does_not_reuse_existing_version(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v2']);
        $this->collectionManager->method('listCollections')->willReturn([['name' => 'rar_p_v2'], ['name' => 'rar_p_v3']]);
        $this->nameResolver->expects(self::once())->method('resolveVersioned')->with('p', 'de', 4, null)->willReturn('rar_p_v4');

        self::assertSame('rar_p_v4', $this->sut->startReindex('p', 'de', []));
    }

    public function test_finish_reindex_runs_appliers_before_alias_swap(): void
    {
        $calls = [];
        $this->nameResolver->method('resolve')->with('product', 'de', 3)->willReturn('rar_product_de');
        $this->collectionManager->method('listCollections')->willReturn([]);

        $this->applier->expects(self::once())
            ->method('apply')
            ->with('product', 3, 'de', 'rar_product_de_v2')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'apply';
            });
        $this->aliasManager->expects(self::once())
            ->method('upsertAlias')
            ->with('rar_product_de', 'rar_product_de_v2')
            ->willReturnCallback(static function () use (&$calls): array {
                $calls[] = 'upsertAlias';

                return [];
            });

        $this->sut->finishReindex('product', 'de', 'rar_product_de_v2', 3);

        self::assertSame(['apply', 'upsertAlias'], $calls);
    }

    public function test_failing_applier_does_not_block_the_first_swap(): void
    {
        $second = $this->createMock(PostSwapApplierInterface::class);
        $sut = new ZeroDowntimeService(
            $this->collectionManager,
            $this->aliasManager,
            $this->nameResolver,
            $this->logger,
            ['synonyms' => $this->applier, 'category_merchandising' => $second],
        );
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');
        $this->collectionManager->method('listCollections')->willReturn([]);

        $this->applier->method('apply')->willThrowException(new \RuntimeException('typesense down'));
        $second->expects(self::once())->method('apply');
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('synonyms'));
        $this->aliasManager->expects(self::once())->method('upsertAlias')->with('rar_product_de', 'rar_product_de_v2');

        $sut->finishReindex('product', 'de', 'rar_product_de_v2', 3);
    }

    public function test_finish_reindex_without_store_id_skips_appliers_but_still_swaps(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');
        $this->collectionManager->method('listCollections')->willReturn([]);

        $this->applier->expects(self::never())->method('apply');
        $this->aliasManager->expects(self::once())->method('upsertAlias');

        $this->sut->finishReindex('product', 'de', 'rar_product_de_v2');
    }

    public function test_cleanup_keeps_previously_live_version_and_deletes_failed_build_orphan(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v5']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_p_v5'], ['name' => 'rar_p_v6'], ['name' => 'rar_p_v7'],
        ]);
        $this->aliasManager->expects(self::once())->method('upsertAlias')->with('rar_p', 'rar_p_v7');
        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_p_v6');

        $this->sut->finishReindex('p', 'de', 'rar_p_v7');
    }

    public function test_cleanup_without_previous_alias_keeps_only_new_collection(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willThrowException(new ObjectNotFound());
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_p_v1'], ['name' => 'rar_p_v2'],
        ]);
        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_p_v1');

        $this->sut->finishReindex('p', 'de', 'rar_p_v2');
    }

    public function test_reindex_after_rollback_keeps_rolled_back_to_version(): void
    {
        $aliasTarget = 'rar_p_v6';
        $collections = ['rar_p_v5', 'rar_p_v6'];
        $deleted = [];

        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->nameResolver->method('resolveVersioned')->willReturnCallback(
            static fn (string $type, string $code, int $version): string => 'rar_p_v' . $version,
        );
        $this->aliasManager->method('getAlias')->willReturnCallback(
            static function () use (&$aliasTarget): array {
                return ['collection_name' => $aliasTarget];
            },
        );
        $this->aliasManager->method('upsertAlias')->willReturnCallback(
            static function (string $alias, string $collection) use (&$aliasTarget): array {
                $aliasTarget = $collection;

                return [];
            },
        );
        $this->collectionManager->method('listCollections')->willReturnCallback(
            static function () use (&$collections): array {
                return array_map(static fn (string $name): array => ['name' => $name], $collections);
            },
        );
        $this->collectionManager->method('createCollection')->willReturnCallback(
            static function (string $name) use (&$collections): array {
                $collections[] = $name;

                return [];
            },
        );
        $this->collectionManager->method('deleteCollection')->willReturnCallback(
            static function (string $name) use (&$collections, &$deleted): array {
                $deleted[] = $name;
                $collections = array_values(array_diff($collections, [$name]));

                return [];
            },
        );

        self::assertSame('rar_p_v5', $this->sut->rollback('p', 'de'));
        $new = $this->sut->startReindex('p', 'de', []);
        self::assertSame('rar_p_v7', $new);
        $this->sut->finishReindex('p', 'de', $new);

        self::assertSame(['rar_p_v6'], $deleted);
        self::assertSame(['rar_p_v5', 'rar_p_v7'], $collections);
    }

    public function test_rollback_picks_highest_version_below_current_when_several_exist(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v7']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_p_v5'], ['name' => 'rar_p_v6'], ['name' => 'rar_p_v7'], ['name' => 'rar_p_v9'],
        ]);
        $this->aliasManager->expects(self::once())->method('upsertAlias')->with('rar_p', 'rar_p_v6');

        self::assertSame('rar_p_v6', $this->sut->rollback('p', 'de'));
    }

    public function test_rollback_falls_forward_to_other_version_when_none_below(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_p');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_p_v5']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_p_v5'], ['name' => 'rar_p_v6'], ['name' => 'rar_p_v8'],
        ]);
        $this->aliasManager->expects(self::once())->method('upsertAlias')->with('rar_p', 'rar_p_v8');

        self::assertSame('rar_p_v8', $this->sut->rollback('p', 'de'));
    }

    public function test_failing_applier_keeps_the_live_alias_and_throws(): void
    {
        $second = $this->createMock(PostSwapApplierInterface::class);
        $sut = new ZeroDowntimeService(
            $this->collectionManager,
            $this->aliasManager,
            $this->nameResolver,
            $this->logger,
            ['synonyms' => $this->applier, 'category_merchandising' => $second],
        );
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_product_de_v1']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_product_de_v1'], ['name' => 'rar_product_de_v2'],
        ]);

        $this->applier->method('apply')->willThrowException(new \RuntimeException('typesense down'));
        $second->expects(self::once())->method('apply');
        $this->aliasManager->expects(self::never())->method('upsertAlias');
        $this->collectionManager->expects(self::never())->method('deleteCollection');

        $this->expectException(ApplierFailedException::class);
        $this->expectExceptionMessage('synonyms: typesense down');

        $sut->finishReindex('product', 'de', 'rar_product_de_v2', 3);
    }

    public function test_finish_reindex_reports_a_changed_schema(): void
    {
        $this->useLiveCollectionWithFields(
            [['name' => 'name', 'type' => 'string', 'sort' => false]],
            [['name' => 'name', 'type' => 'string', 'sort' => true]],
        );

        self::assertTrue($this->sut->finishReindex('product', 'de', 'rar_product_de_v2', 3));
    }

    public function test_finish_reindex_reports_an_added_field(): void
    {
        $this->useLiveCollectionWithFields(
            [['name' => 'name', 'type' => 'string']],
            [['name' => 'name', 'type' => 'string'], ['name' => 'volume_percent_sort', 'type' => 'float']],
        );

        self::assertTrue($this->sut->finishReindex('product', 'de', 'rar_product_de_v2', 3));
    }

    public function test_finish_reindex_reports_an_unchanged_schema_regardless_of_field_order(): void
    {
        $this->useLiveCollectionWithFields(
            [['name' => 'name', 'type' => 'string', 'sort' => true], ['name' => 'sku', 'type' => 'string']],
            [['name' => 'sku', 'type' => 'string'], ['sort' => true, 'type' => 'string', 'name' => 'name']],
        );

        self::assertFalse($this->sut->finishReindex('product', 'de', 'rar_product_de_v2', 3));
    }

    public function test_first_build_counts_as_a_changed_schema(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');
        $this->aliasManager->method('getAlias')->willThrowException(new ObjectNotFound());
        $this->collectionManager->method('listCollections')->willReturn([]);

        self::assertTrue($this->sut->finishReindex('product', 'de', 'rar_product_de_v1', 3));
    }

    public function test_unreadable_schema_counts_as_changed(): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_product_de_v1']);
        $this->collectionManager->method('listCollections')->willReturn([]);
        $this->collectionManager->method('getCollection')->willThrowException(new \RuntimeException('timeout'));

        self::assertTrue($this->sut->finishReindex('product', 'de', 'rar_product_de_v2', 3));
    }

    /**
     * @param list<array<string, mixed>> $liveFields
     * @param list<array<string, mixed>> $newFields
     */
    private function useLiveCollectionWithFields(array $liveFields, array $newFields): void
    {
        $this->nameResolver->method('resolve')->willReturn('rar_product_de');
        $this->aliasManager->method('getAlias')->willReturn(['collection_name' => 'rar_product_de_v1']);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_product_de_v1'], ['name' => 'rar_product_de_v2'],
        ]);
        $this->collectionManager->method('getCollection')->willReturnMap([
            ['rar_product_de_v1', ['name' => 'rar_product_de_v1', 'fields' => $liveFields]],
            ['rar_product_de_v2', ['name' => 'rar_product_de_v2', 'fields' => $newFields]],
        ]);
    }
}
