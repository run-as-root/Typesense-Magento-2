<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class StockResolver implements StockResolverInterface
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
    ) {
    }

    public function isInStock(Product $product): bool
    {
        $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());

        return $stockItem->getIsInStock();
    }
}
