<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Suggestion;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;
use RunAsRoot\TypeSense\Model\Indexer\Suggestion\SuggestionIndexer;

final class SuggestionIndexerTest extends TestCase
{
    public function test_empty_id_list_does_not_trigger_a_full_reindex(): void
    {
        $orchestrator = $this->createMock(IndexerOrchestrator::class);
        $orchestrator->expects(self::never())->method('reindex');
        $sut = new SuggestionIndexer($orchestrator);

        $sut->executeList([]);
    }

    public function test_id_list_is_reindexed_partially(): void
    {
        $orchestrator = $this->createMock(IndexerOrchestrator::class);
        $orchestrator->expects(self::once())->method('reindex')->with('suggestion', [4, 5]);

        (new SuggestionIndexer($orchestrator))->executeList(['4', '5']);
    }
}
