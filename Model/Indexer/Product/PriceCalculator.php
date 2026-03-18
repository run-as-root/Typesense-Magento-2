<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

class PriceCalculator implements PriceCalculatorInterface
{
    public function getFinalPrice(Product $product): float
    {
        $priceInfo = $product->getPriceInfo();
        if ($priceInfo !== null) {
            $finalPriceModel = $priceInfo->getPrice('final_price');
            if ($finalPriceModel !== null) {
                return (float) $finalPriceModel->getValue();
            }
        }

        return (float) ($product->getData('price') ?? 0.0);
    }

    public function getSpecialPrice(Product $product): ?float
    {
        $specialPrice = $product->getData('special_price');
        if ($specialPrice === null || $specialPrice === '') {
            return null;
        }

        $price = (float) $specialPrice;
        if ($price <= 0.0) {
            return null;
        }

        return $price;
    }
}
