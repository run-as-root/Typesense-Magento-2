<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class InstantSearchConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isInstantSearchEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
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
            'productsPerPage'           => $this->config->getProductsPerPage(),
            'facetAttributes'           => $this->getFacetAttributes(),
            'sortOptions'               => $this->getSortOptions(),
        ];
    }

    public function getJsonConfig(): string
    {
        return (string) json_encode($this->getConfig());
    }

    /**
     * @return string[]
     */
    public function getFacetAttributes(): array
    {
        return ['category_ids', 'brand', 'color', 'size', 'in_stock'];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getSortOptions(): array
    {
        return [
            ['label' => 'Relevance', 'value' => ''],
            ['label' => 'Price: Low to High', 'value' => 'price:asc'],
            ['label' => 'Price: High to Low', 'value' => 'price:desc'],
            ['label' => 'Newest', 'value' => 'created_at:desc'],
        ];
    }
}
