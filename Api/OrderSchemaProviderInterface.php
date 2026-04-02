<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface OrderSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array;
}
