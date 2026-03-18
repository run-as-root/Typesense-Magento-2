<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

interface StockResolverInterface
{
    public function isInStock(Product $product): bool;
}
