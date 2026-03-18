<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class AutocompleteConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isAutocompleteEnabled();
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
            'categoryCollection'        => $this->collectionNameResolver->resolve('category', $storeCode, $storeId),
            'suggestionCollection'      => $this->collectionNameResolver->resolve('suggestion', $storeCode, $storeId),
            'productCount'              => $this->config->getAutocompleteProductCount(),
        ];
    }

    public function getJsonConfig(): string
    {
        return (string) json_encode($this->getConfig());
    }
}
