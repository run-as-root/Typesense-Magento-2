<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

/**
 * Optional for entity indexers whose documents Typesense writes itself (e.g. search analytics). A full reindex only
 * provisions what the entity needs, it never rebuilds, swaps or empties the collection; partial reindexes do nothing.
 */
interface SelfMaintainedEntityInterface
{
    /**
     * Creates the collection and anything feeding it when missing; existing documents are left alone.
     */
    public function provision(int $storeId, string $storeCode): void;
}
