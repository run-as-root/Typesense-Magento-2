<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;
use RunAsRoot\TypeSense\Model\Indexer\Product\ParentIdResolver;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductIndexer;
use RunAsRoot\TypeSense\Queue\Publisher\ReindexPublisher;

final class ProductIndexerTest extends TestCase
{
    private IndexerOrchestrator&MockObject $orchestrator;
    private ParentIdResolver&MockObject $parentIdResolver;
    private ReindexPublisher&MockObject $publisher;
    private TypeSenseConfigInterface&MockObject $config;
    private ProductIndexer $sut;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(IndexerOrchestrator::class);
        $this->parentIdResolver = $this->createMock(ParentIdResolver::class);

        $this->publisher = $this->createMock(ReindexPublisher::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);

        $this->sut = new ProductIndexer(
            $this->orchestrator,
            $this->parentIdResolver,
            $this->publisher,
            $this->config,
        );
    }

    public function test_execute_list_adds_parent_ids(): void
    {
        $this->parentIdResolver->method('getParentIds')->with([101])->willReturn([42]);
        $this->orchestrator->expects(self::once())->method('reindex')->with('product', [101, 42]);

        $this->sut->executeList(['101']);
    }

    public function test_execute_row_adds_parent_ids(): void
    {
        $this->parentIdResolver->method('getParentIds')->with([101])->willReturn([42]);
        $this->orchestrator->expects(self::once())->method('reindex')->with('product', [101, 42]);

        $this->sut->executeRow('101');
    }

    public function test_execute_deduplicates_ids_already_requested(): void
    {
        $this->parentIdResolver->method('getParentIds')->willReturn([42]);
        $this->orchestrator->expects(self::once())->method('reindex')->with('product', [42, 101]);

        $this->sut->execute([42, 101]);
    }

    public function test_execute_full_does_not_resolve_parents(): void
    {
        $this->parentIdResolver->expects(self::never())->method('getParentIds');
        $this->orchestrator->expects(self::once())->method('reindex')->with('product');

        $this->sut->executeFull();
    }

    public function test_execute_list_publishes_ids_when_queue_enabled(): void
    {
        $this->config->method('isQueueEnabled')->willReturn(true);
        $this->parentIdResolver->method('getParentIds')->willReturn([]);
        $this->publisher->expects(self::once())->method('publish')->with('product', null, [7]);
        $this->orchestrator->expects(self::never())->method('reindex');

        $this->sut->executeList([7]);
    }

    public function test_execute_list_publishes_ids_with_parents_when_queue_enabled(): void
    {
        $this->config->method('isQueueEnabled')->willReturn(true);
        $this->parentIdResolver->method('getParentIds')->willReturn([42]);
        $this->publisher->expects(self::once())->method('publish')->with('product', null, [7, 42]);
        $this->orchestrator->expects(self::never())->method('reindex');

        $this->sut->execute([7]);
    }

    public function test_execute_list_with_no_ids_does_nothing(): void
    {
        $this->publisher->expects(self::never())->method('publish');
        $this->orchestrator->expects(self::never())->method('reindex');

        $this->sut->executeList([]);
        $this->sut->execute([]);
    }

    public function test_execute_full_ignores_queue_setting(): void
    {
        $this->config->method('isQueueEnabled')->willReturn(true);
        $this->publisher->expects(self::never())->method('publish');
        $this->orchestrator->expects(self::once())->method('reindex')->with('product');

        $this->sut->executeFull();
    }
}
