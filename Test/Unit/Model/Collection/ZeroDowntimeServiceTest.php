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

        $this->collectionManager->expects(self::exactly(2))
            ->method('deleteCollection')
            ->with(self::logicalOr(
                self::equalTo('rar_products_default_v1'),
                self::equalTo('rar_products_default_v2'),
            ));

        $this->sut->finishReindex('products', 'default', 'rar_products_default_v3');
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

    public function test_failing_applier_does_not_block_swap(): void
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
}
