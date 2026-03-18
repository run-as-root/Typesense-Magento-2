<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\LandingPageInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class LandingPageViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly Registry $registry,
    ) {
    }

    public function getLandingPage(): ?LandingPageInterface
    {
        return $this->registry->registry('current_landing_page');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchConfig(): array
    {
        $landingPage = $this->getLandingPage();
        $store = $this->storeManager->getStore();
        $storeCode = $store->getCode();
        $storeId = (int) $store->getId();

        return [
            'typesenseHost'             => $this->config->getHost(),
            'typesensePort'             => $this->config->getPort(),
            'typesenseProtocol'         => $this->config->getProtocol(),
            'typesenseSearchOnlyApiKey' => $this->config->getSearchOnlyApiKey(),
            'productCollection'         => $this->collectionNameResolver->resolve('product', $storeCode, $storeId),
            'query'                     => $landingPage?->getQuery() ?: '*',
            'filterBy'                  => $landingPage?->getFilterBy() ?: '',
            'sortBy'                    => $landingPage?->getSortBy() ?: '',
            'productsPerPage'           => $this->config->getProductsPerPage(),
            'facetAttributes'           => ['brand', 'color', 'size', 'in_stock', 'price'],
        ];
    }

    public function getJsonSearchConfig(): string
    {
        return (string) json_encode($this->getSearchConfig());
    }

    public function getCmsContent(): string
    {
        $landingPage = $this->getLandingPage();
        if ($landingPage === null) {
            return '';
        }

        return $landingPage->getCmsContent() ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getBannerConfig(): array
    {
        $landingPage = $this->getLandingPage();
        if ($landingPage === null) {
            return [];
        }

        return $landingPage->getBannerConfig();
    }

    public function hasBanner(): bool
    {
        return !empty($this->getBannerConfig());
    }
}
