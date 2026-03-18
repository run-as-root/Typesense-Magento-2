<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

interface ImageResolverInterface
{
    public function getImageUrl(Product $product): string;
}
