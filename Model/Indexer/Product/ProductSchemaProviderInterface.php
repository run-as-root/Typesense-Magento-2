<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use RunAsRoot\TypeSense\Api\CollectionSettingsProviderInterface;

interface ProductSchemaProviderInterface extends CollectionSettingsProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array;
}
