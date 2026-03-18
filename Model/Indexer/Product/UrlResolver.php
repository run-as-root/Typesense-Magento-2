<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;

readonly class UrlResolver implements UrlResolverInterface
{
    public function __construct(
        private ProductUrl $productUrl,
    ) {
    }

    public function getProductUrl(Product $product): string
    {
        return $this->productUrl->getUrl($product);
    }
}
