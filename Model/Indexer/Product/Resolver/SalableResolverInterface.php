<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product;

interface SalableResolverInterface
{
    public function isSalable(Product $product, int $storeId): bool;

    /**
     * The quantity step for adding the product to the cart; 1 when increments are not enforced.
     */
    public function getQtyIncrement(Product $product, int $storeId): float;
}
