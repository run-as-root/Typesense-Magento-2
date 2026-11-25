<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

use Magento\Framework\Indexer\ActionInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

/**
 * A full reindex creates missing suggestion collections and analytics rules. Typesense writes the documents
 * itself, so there are no row updates and no mview subscriptions.
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
    }

    public function executeRow($id): void
    {
    }
}
