<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

/**
 * Optional for entity indexers: collection-level schema settings applied when the collection is created.
 */
interface CollectionSettingsProviderInterface
{
    /**
     * Merged into the collection schema next to `fields`, e.g. ['enable_nested_fields' => true].
     *
     * @return array<string, mixed>
     */
    public function getCollectionSettings(): array;
}
