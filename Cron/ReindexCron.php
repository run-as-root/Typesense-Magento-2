<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Cron;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class ReindexCron
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly IndexerOrchestratorInterface $orchestrator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isCronEnabled()) {
            return;
        }

        try {
            $this->orchestrator->reindexAll();
        } catch (\Exception $e) {
            $this->logger->error('TypeSense cron reindex failed: ' . $e->getMessage());
        }
    }
}
