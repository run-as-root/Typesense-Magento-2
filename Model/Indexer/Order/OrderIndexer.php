<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

class OrderIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly IndexerOrchestrator $orchestrator,
    ) {
    }

    public function executeFull(): void
    {
        $this->orchestrator->reindex('order');
    }

    public function executeList(array $ids): void
    {
        $this->orchestrator->reindex('order', array_map('intval', $ids));
    }

    public function executeRow($id): void
    {
        $this->orchestrator->reindex('order', [(int) $id]);
    }

    public function execute($ids): void
    {
        $this->orchestrator->reindex('order', array_map('intval', $ids));
    }
}
