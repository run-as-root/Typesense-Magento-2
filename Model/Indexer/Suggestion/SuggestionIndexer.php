<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

use Magento\Framework\Indexer\ActionInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

/**
 * Note: Suggestions have no mview subscription since they are updated on search, not on entity save.
 * This indexer is triggered manually or via cron.
 */
class SuggestionIndexer implements ActionInterface
{
    public function __construct(
        private readonly IndexerOrchestrator $orchestrator,
    ) {
    }

    public function executeFull(): void
    {
        $this->orchestrator->reindex('suggestion');
    }

    public function executeList(array $ids): void
    {
        $this->reindexIds($ids);
    }

    public function executeRow($id): void
    {
        $this->orchestrator->reindex('suggestion', [(int) $id]);
    }

    /**
     * @param array<int|string> $ids
     */
    private function reindexIds(array $ids): void
    {
        // An empty list must not reach the orchestrator, where it means a full reindex.
        if ($ids === []) {
            return;
        }

        $this->orchestrator->reindex('suggestion', array_map('intval', $ids));
    }
}
