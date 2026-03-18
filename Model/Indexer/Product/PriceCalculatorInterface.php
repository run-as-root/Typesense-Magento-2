<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

interface PriceCalculatorInterface
{
    public function getFinalPrice(Product $product): float;

    public function getSpecialPrice(Product $product): ?float;
}
