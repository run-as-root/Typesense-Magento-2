<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class ImageResolver implements ImageResolverInterface
{
    public function __construct(
        private readonly ImageHelper $imageHelper,
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function getImageUrl(Product $product): string
    {
        $image = $product->getImage();

        // The helper would return the placeholder image; the storefront decides how to show a missing image.
        if (!$image || $image === 'no_selection') {
            return '';
        }

        try {
            return $this->imageHelper
                ->init($product, $this->config->getListingImageId())
                ->getUrl();
        } catch (\Exception) {
            return '';
        }
    }
}
