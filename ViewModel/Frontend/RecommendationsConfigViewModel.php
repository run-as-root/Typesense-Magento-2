<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * Configuration of `product/recommendations.phtml`. It holds no connection data: requests go through the shared
 * client of `js/client-loader.phtml`.
 */
class RecommendationsConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isRecommendationsEnabled();
    }

    /**
     * Shops that hide prices (e.g. for guests) change this with an after plugin, as on
     * ListingConfigViewModel::canShowPrices().
     */
    public function canShowPrices(): bool
    {
        return true;
    }

    /**
     * @return array{productCollection: string, productId: string, limit: int, showPrices: bool}
     */
    public function getConfig(ProductInterface $product): array
    {
        $store = $this->storeManager->getStore();
        $storeCode = $store->getCode();
        $storeId = (int) $store->getId();

        return [
            'productCollection' => $this->collectionNameResolver->resolve('product', $storeCode, $storeId),
            'productId'         => (string) $product->getId(),
            'limit'             => $this->config->getRecommendationsLimit(),
            'showPrices'        => $this->canShowPrices(),
        ];
    }

    public function getJsonConfig(ProductInterface $product): string
    {
        return (string) json_encode(
            $this->getConfig($product),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }
}
