<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface EntityIndexerInterface
{
    public function getEntityType(): string;

    public function getSchemaFields(): array;

    public function getIndexerCode(): string;

    /**
     * Yields documents to be indexed. Uses generator for memory efficiency.
     *
     * @param int[] $entityIds Empty array = full reindex
     * @return iterable<array<string, mixed>>
     */
    public function buildDocuments(array $entityIds, int $storeId): iterable;
}
