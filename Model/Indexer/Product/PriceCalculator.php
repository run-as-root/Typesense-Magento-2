<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;

class PriceCalculator implements PriceCalculatorInterface
{
    public function getFinalPrice(Product $product): float
    {
        return $this->getAmount($product, FinalPrice::PRICE_CODE);
    }

    public function getRegularPrice(Product $product): float
    {
        return $this->getAmount($product, RegularPrice::PRICE_CODE);
    }

    private function getAmount(Product $product, string $priceCode): float
    {
        return (float) $product->getPriceInfo()->getPrice($priceCode)->getAmount()->getValue();
    }
}
