<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

interface SalesCountResolverInterface
{
    public function getSalesCount(int $productId): int;
}
