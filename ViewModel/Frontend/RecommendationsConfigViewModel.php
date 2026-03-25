<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

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
     * @return array<string, mixed>
     */
    public function getConfig(ProductInterface $product): array
    {
        $store = $this->storeManager->getStore();
        $storeCode = $store->getCode();
        $storeId = (int) $store->getId();

        return [
            'typesenseHost'             => $this->config->getSearchHost(),
            'typesensePort'             => $this->config->getSearchPort(),
            'typesenseProtocol'         => $this->config->getSearchProtocol(),
            'typesenseSearchOnlyApiKey' => $this->config->getSearchOnlyApiKey(),
            'productCollection'         => $this->collectionNameResolver->resolve('product', $storeCode, $storeId),
            'productId'                 => (string) $product->getId(),
            'limit'                     => $this->config->getRecommendationsLimit(),
        ];
    }

    public function getJsonConfig(ProductInterface $product): string
    {
        return (string) json_encode($this->getConfig($product));
    }
}
