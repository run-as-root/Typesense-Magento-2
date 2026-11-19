<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\CmsPage;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

class CmsPageIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly IndexerOrchestrator $orchestrator,
    ) {
    }

    public function executeFull(): void
    {
        $this->orchestrator->reindex('cms_page');
    }

    public function executeList(array $ids): void
    {
        $this->reindexIds($ids);
    }

    public function executeRow($id): void
    {
        $this->orchestrator->reindex('cms_page', [(int) $id]);
    }

    public function execute($ids): void
    {
        $this->reindexIds($ids);
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

        $this->orchestrator->reindex('cms_page', array_map('intval', $ids));
    }
}
