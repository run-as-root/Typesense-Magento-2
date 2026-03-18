<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface IndexerOrchestratorInterface
{
    /**
     * Reindex a specific entity type, optionally for specific entity IDs.
     *
     * @param int[] $entityIds Empty = full reindex
     */
    public function reindex(string $entityType, array $entityIds = []): void;

    /**
     * Reindex all registered entity types.
     */
    public function reindexAll(): void;
}
