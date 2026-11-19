<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Order;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;
use RunAsRoot\TypeSense\Model\Indexer\Order\OrderIndexer;

final class OrderIndexerTest extends TestCase
{
    public function test_empty_id_list_does_not_trigger_a_full_reindex(): void
    {
        $orchestrator = $this->createMock(IndexerOrchestrator::class);
        $orchestrator->expects(self::never())->method('reindex');
        $sut = new OrderIndexer($orchestrator);

        $sut->executeList([]);
        $sut->execute([]);
    }

    public function test_id_list_is_reindexed_partially(): void
    {
        $orchestrator = $this->createMock(IndexerOrchestrator::class);
        $orchestrator->expects(self::once())->method('reindex')->with('order', [4, 5]);

        (new OrderIndexer($orchestrator))->executeList(['4', '5']);
    }
}
