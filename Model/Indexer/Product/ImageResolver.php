<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;

class ImageResolver implements ImageResolverInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function getImageUrl(Product $product): string
    {
        $image = $product->getImage();

        if (!$image || $image === 'no_selection') {
            return '';
        }

        try {
            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(
                \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
            );

            return $mediaUrl . 'catalog/product' . $image;
        } catch (\Exception) {
            return '';
        }
    }
}
