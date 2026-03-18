<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;

readonly class ImageResolver implements ImageResolverInterface
{
    public function __construct(
        private ImageHelper $imageHelper,
    ) {
    }

    public function getImageUrl(Product $product): string
    {
        return $this->imageHelper
            ->init($product, 'product_page_image_large')
            ->getUrl();
    }
}
