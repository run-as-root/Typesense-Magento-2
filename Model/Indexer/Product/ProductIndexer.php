<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

class ProductIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly IndexerOrchestrator $orchestrator,
        private readonly ParentIdResolver $parentIdResolver,
    ) {
    }

    public function executeFull(): void
    {
        $this->orchestrator->reindex('product');
    }

    public function executeList(array $ids): void
    {
        $this->reindexWithParents(array_map('intval', $ids));
    }

    public function executeRow($id): void
    {
        $this->reindexWithParents([(int) $id]);
    }

    public function execute($ids): void
    {
        $this->reindexWithParents(array_map('intval', $ids));
    }

    /**
     * @param int[] $ids
     */
    private function reindexWithParents(array $ids): void
    {
        // Parents are expanded here only: a changed child alters its parent's document, and the orchestrator must not re-expand.
        $ids = array_values(array_unique([...$ids, ...$this->parentIdResolver->getParentIds($ids)]));

        $this->orchestrator->reindex('product', $ids);
    }
}
