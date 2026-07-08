<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

class CategorySearchConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly Registry $registry,
        private readonly CategoryVirtualRuleResolver $virtualRuleResolver,
        private readonly LoggerInterface $logger,
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

    public function getCategoryFilterBy(): ?string
    {
        $categoryId = $this->getCurrentCategoryId();
        if ($categoryId === null) {
            return null;
        }

        try {
            return $this->virtualRuleResolver->resolveFilter($categoryId, (int) $this->storeManager->getStore()->getId());
        } catch (\Throwable $e) {
            // Graceful degradation, matching the pattern conversational search already uses:
            // never let a corrupted/malformed rule (bad conditions_serialized JSON, a compiler
            // error, a circular virtual-root reference, ...) fatal the storefront category page.
            // NO_MATCH_FILTER (not null) so a broken rule renders an empty grid rather than
            // silently exposing the entire catalog by dropping the filter altogether.
            $this->logger->error(
                sprintf('CategorySearchConfigViewModel: failed to resolve virtual category filter for category %d - %s', $categoryId, $e->getMessage()),
                ['exception' => $e],
            );

            return ConditionsToFilterByCompiler::NO_MATCH_FILTER;
        }
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
            'categoryFilterBy'          => $this->getCategoryFilterBy(),
            'productsPerPage'           => $this->config->getProductsPerPage(),
            'facetAttributes'           => $this->config->getFacetFilters(),
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
