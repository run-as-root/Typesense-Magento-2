<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface as MsiStockResolverInterface;
use Magento\Store\Model\StoreManagerInterface;

class SalableResolver implements SalableResolverInterface
{
    /** @var array<int, int> store id => stock id */
    private array $stockIds = [];

    public function __construct(
        private readonly IsProductSalableInterface $isProductSalable,
        private readonly MsiStockResolverInterface $msiStockResolver,
        private readonly GetStockItemConfigurationInterface $getStockItemConfiguration,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function isSalable(Product $product, int $storeId): bool
    {
        return $this->isProductSalable->execute((string) $product->getSku(), $this->getStockId($storeId));
    }

    public function getQtyIncrement(Product $product, int $storeId): float
    {
        try {
            $configuration = $this->getStockItemConfiguration->execute(
                (string) $product->getSku(),
                $this->getStockId($storeId),
            );
        } catch (LocalizedException) {
            // A SKU not assigned to the store's stock has no configuration, so no increment is enforced.
            return 1.0;
        }

        $increment = $configuration->isEnableQtyIncrements() ? $configuration->getQtyIncrements() : 0.0;

        return $increment > 0 ? $increment : 1.0;
    }

    private function getStockId(int $storeId): int
    {
        if (!isset($this->stockIds[$storeId])) {
            $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
            $websiteCode = (string) $this->storeManager->getWebsite($websiteId)->getCode();
            $stock = $this->msiStockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
            $this->stockIds[$storeId] = (int) $stock->getStockId();
        }

        return $this->stockIds[$storeId];
    }
}
