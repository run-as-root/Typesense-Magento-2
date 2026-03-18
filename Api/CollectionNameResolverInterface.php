<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface CollectionNameResolverInterface
{
    public function resolve(string $entityType, string $storeCode, ?int $storeId = null): string;

    public function resolveVersioned(string $entityType, string $storeCode, int $version, ?int $storeId = null): string;
}
