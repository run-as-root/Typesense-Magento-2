<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

/**
 * Re-creates collection-level state (synonyms, overrides) on a freshly built collection before
 * the alias is swapped to it, since that state lives on the collection, not the alias.
 */
interface PostSwapApplierInterface
{
    public function apply(string $entityType, int $storeId, string $storeCode, string $collectionName): void;
}
