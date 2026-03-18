<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Category;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

readonly class CategoryIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(
        private IndexerOrchestrator $orchestrator,
    ) {
    }

    public function executeFull(): void
    {
        $this->orchestrator->reindex('category');
    }

    public function executeList(array $ids): void
    {
        $this->orchestrator->reindex('category', array_map('intval', $ids));
    }

    public function executeRow($id): void
    {
        $this->orchestrator->reindex('category', [(int) $id]);
    }

    public function execute($ids): void
    {
        $this->orchestrator->reindex('category', array_map('intval', $ids));
    }
}
