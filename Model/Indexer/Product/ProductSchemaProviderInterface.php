<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

interface ProductSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array;
}
