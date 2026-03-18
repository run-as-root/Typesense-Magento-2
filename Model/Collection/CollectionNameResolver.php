<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection;

use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

readonly class CollectionNameResolver implements CollectionNameResolverInterface
{
    public function __construct(private TypeSenseConfigInterface $config)
    {
    }

    public function resolve(string $entityType, string $storeCode, ?int $storeId = null): string
    {
        return $this->config->getCollectionName($entityType, $storeCode, $storeId);
    }

    public function resolveVersioned(string $entityType, string $storeCode, int $version, ?int $storeId = null): string
    {
        return sprintf('%s_v%d', $this->resolve($entityType, $storeCode, $storeId), $version);
    }
}
