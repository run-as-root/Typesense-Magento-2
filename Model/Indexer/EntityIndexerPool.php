<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use RunAsRoot\TypeSense\Api\EntityIndexerInterface;

class EntityIndexerPool
{
    /** @param array<string, EntityIndexerInterface> $indexers */
    public function __construct(private readonly array $indexers = [])
    {
    }

    public function getIndexer(string $entityType): EntityIndexerInterface
    {
        if (!isset($this->indexers[$entityType])) {
            throw new \InvalidArgumentException("No indexer registered for entity type: {$entityType}");
        }

        return $this->indexers[$entityType];
    }

    /** @return array<string, EntityIndexerInterface> */
    public function getAll(): array
    {
        return $this->indexers;
    }

    public function hasIndexer(string $entityType): bool
    {
        return isset($this->indexers[$entityType]);
    }
}
