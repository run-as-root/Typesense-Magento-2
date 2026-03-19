<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class CategorySearchConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly Registry $registry,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isReplaceCategoryPage();
    }

    public function getCurrentCategoryId(): ?int
    {
        $category = $this->registry->registry('current_category');
        if ($category === null) {
            return null;
        }

        return (int) $category->getId() ?: null;
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
            'categoryId'                => $this->getCurrentCategoryId(),
            'productsPerPage'           => $this->config->getProductsPerPage(),
            'facetAttributes'           => ['categories.lvl0', 'in_stock', 'type_id'],
            'sortOptions'               => $this->getSortOptions(),
            'tileAttributes'            => $this->config->getTileAttributes(),
        ];
    }

    public function getJsonConfig(): string
    {
        return (string) json_encode($this->getConfig());
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getSortOptions(): array
    {
        return $this->config->getEnabledSortOptions();
    }
}
