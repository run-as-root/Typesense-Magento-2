<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

interface UrlResolverInterface
{
    public function getProductUrl(Product $product): string;
}
