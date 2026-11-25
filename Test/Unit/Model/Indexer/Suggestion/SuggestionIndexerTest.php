<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Suggestion;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;
use RunAsRoot\TypeSense\Model\Indexer\Suggestion\SuggestionIndexer;

final class SuggestionIndexerTest extends TestCase
{
    public function test_full_reindex_provisions_the_suggestion_entity(): void
    {
        $orchestrator = $this->createMock(IndexerOrchestrator::class);
        $orchestrator->expects(self::once())->method('reindex')->with('suggestion');

        (new SuggestionIndexer($orchestrator))->executeFull();
    }

    public function test_row_updates_do_nothing_because_typesense_maintains_the_documents(): void
    {
        $orchestrator = $this->createMock(IndexerOrchestrator::class);
        $orchestrator->expects(self::never())->method('reindex');
        $sut = new SuggestionIndexer($orchestrator);

        $sut->executeList([4, 5]);
        $sut->executeList([]);
        $sut->executeRow(4);
    }
}
