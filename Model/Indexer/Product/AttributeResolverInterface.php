<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

interface AttributeResolverInterface
{
    /**
     * Return extra EAV attribute key/value pairs for the given product.
     *
     * @return array<string, mixed>
     */
    public function getExtraAttributes(Product $product): array;
}
