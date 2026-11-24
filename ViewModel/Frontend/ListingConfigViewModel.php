<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Conversation\ConversationModelManager;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetResolver;
use RunAsRoot\TypeSense\Model\Listing\FacetConfigBuilder;
use RunAsRoot\TypeSense\Model\Search\SearchParametersProvider;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryListingFilterBuilder;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

/**
 * Configuration of the storefront listing component (`typesenseListing`) for the search and category pages.
 */
class ListingConfigViewModel implements ArgumentInterface
{
    public const string CONTEXT_SEARCH = 'search';
    public const string CONTEXT_CATEGORY = 'category';

    private const string SEARCH_BASE_FILTER = 'visibility:[3,4]';
    private const int MAX_FACET_VALUES = 50;
    // Magento sort attribute codes mapped to the module's sort option values ('' is relevance).
    private const array MAGENTO_SORT_MAP = [
        'position' => '',
        'relevance' => '',
        'name' => 'name:asc',
        'price' => 'price:asc',
    ];

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly Registry $registry,
        private readonly CategoryListingFilterBuilder $listingFilterBuilder,
        private readonly SearchRequestParamsBuilder $requestParamsBuilder,
        private readonly SearchParametersProvider $searchParametersProvider,
        private readonly FacetConfigBuilder $facetConfigBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly LocaleResolverInterface $localeResolver,
        private readonly ConversationModelManager $conversationModelManager,
        private readonly LoggerInterface $logger,
        private readonly CategoryFacetResolver $categoryFacetResolver,
    ) {
    }

    public function isEnabled(string $context): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $context === self::CONTEXT_CATEGORY
            ? $this->config->isReplaceCategoryPage()
            : $this->config->isInstantSearchEnabled();
    }

    /**
     * Prices on tiles, in the ItemList JSON-LD offers and in GA4 items. Shops that hide prices
     * (e.g. for guests) change this with an after plugin.
     */
    public function canShowPrices(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(string $context): array
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $isCategory = $context === self::CONTEXT_CATEGORY;
        $paramsContext = $isCategory
            ? SearchRequestParamsBuilder::CONTEXT_CATEGORY
            : SearchRequestParamsBuilder::CONTEXT_SEARCH;
        $category = $isCategory ? $this->getCurrentCategory() : null;
        $limits = $this->getCatalogLimits($storeId);

        return [
            // Only the search-only key may reach the browser.
            'connection' => [
                'host' => $this->config->getSearchHost(),
                'port' => $this->config->getSearchPort(),
                'protocol' => $this->config->getSearchProtocol(),
                'apiKey' => $this->config->getSearchOnlyApiKey(),
            ],
            'productCollection' => $this->collectionNameResolver->resolve('product', $store->getCode(), $storeId),
            'context' => $isCategory ? self::CONTEXT_CATEGORY : self::CONTEXT_SEARCH,
            'categoryId' => $category !== null ? (int) $category->getId() : null,
            'baseFilter' => $isCategory ? $this->getCategoryFilter($category, $storeId) : self::SEARCH_BASE_FILTER,
            'facets' => $this->getFacets($isCategory, $category, $store),
            'maxFacetValues' => self::MAX_FACET_VALUES,
            'sortOptions' => $this->getSortOptions($category, $storeId),
            'defaultSort' => $category !== null ? $this->mapMagentoSort((string) $category->getDefaultSortBy()) : '',
            'limits' => $limits,
            'perPage' => $this->getPerPage($isCategory, $limits, $storeId),
            'inStockFirst' => $this->config->isInStockFirst($storeId),
            'requestParams' => $this->requestParamsBuilder->build($paramsContext, $storeId),
            'cacheParams' => $this->searchParametersProvider->getCacheParameters($storeId),
            'relevanceSortBy' => $this->requestParamsBuilder->getRelevanceSortBy($paramsContext, $storeId),
            'directMatchesFirst' => !$isCategory && $this->config->isDirectMatchesFirstEnabled($storeId),
            'conversationalSearch' => [
                'enabled' => !$isCategory && $this->config->isConversationalSearchEnabled($storeId),
                'modelId' => $this->conversationModelManager->getModelId(),
            ],
            'listName' => $category !== null ? (string) $category->getName() : (string) __('Search Results'),
            'currencyCode' => (string) $store->getCurrentCurrencyCode(),
            'locale' => str_replace('_', '-', (string) $this->localeResolver->getLocale()),
            'showPrices' => $this->canShowPrices(),
            'showRatings' => $this->scopeConfig->isSetFlag('catalog/review/active', ScopeInterface::SCOPE_STORE, $storeId),
            'addToCartUrl' => $this->urlBuilder->getUrl('checkout/cart/add'),
            'wishlistEnabled' => $this->scopeConfig->isSetFlag('wishlist/general/active', ScopeInterface::SCOPE_STORE, $storeId),
            'wishlistAddUrl' => $this->urlBuilder->getUrl('wishlist/index/add'),
            'wishlistUrl' => $this->urlBuilder->getUrl('wishlist'),
            'imageWidth' => $this->config->getListingImageWidth($storeId),
            'imageHeight' => $this->config->getListingImageHeight($storeId),
            'noResultsCategoryId' => $this->config->getNoResultsCategoryId($storeId),
            'seoTextSelector' => $this->config->getSeoTextSelector($storeId),
        ];
    }

    public function getJsonConfig(string $context): string
    {
        return (string) json_encode(
            $this->getConfig($context),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }

    private function getCurrentCategory(): ?Category
    {
        $category = $this->registry->registry('current_category');

        return $category instanceof Category && $category->getId() ? $category : null;
    }

    private function getCategoryFilter(?Category $category, int $storeId): string
    {
        if ($category === null) {
            return ConditionsToFilterByCompiler::NO_MATCH_FILTER;
        }

        try {
            return $this->listingFilterBuilder->build((int) $category->getId(), $storeId);
        } catch (\Throwable $e) {
            // A broken virtual rule must render an empty grid, not fatal the page or expose the whole catalog.
            $this->logger->error(
                sprintf('Typesense listing: failed to resolve the filter for category %d: %s', (int) $category->getId(), $e->getMessage()),
                ['exception' => $e],
            );

            return ConditionsToFilterByCompiler::NO_MATCH_FILTER;
        }
    }

    /**
     * The category facet comes first whenever it applies, like Magento's category filter; the configured facets
     * never contain a category field (see FacetConfigBuilder).
     *
     * @return list<array<string, mixed>>
     */
    private function getFacets(bool $isCategory, ?Category $category, Store $store): array
    {
        $storeId = (int) $store->getId();
        $facets = $this->facetConfigBuilder->build($storeId, !$isCategory);
        if ($isCategory) {
            $categoryFacet = $category !== null ? $this->categoryFacetResolver->resolve($category, $storeId) : null;
        } else {
            $categoryFacet = $this->categoryFacetResolver->resolveForStoreRoot((int) $store->getRootCategoryId(), $storeId);
        }

        return $categoryFacet !== null ? [$categoryFacet, ...$facets] : $facets;
    }

    /** @return list<array{label: string, value: string}> */
    private function getSortOptions(?Category $category, int $storeId): array
    {
        $options = [];
        $showPrices = $this->canShowPrices();
        $configured = $this->config->getEnabledSortOptions($storeId);
        foreach ($configured as $option) {
            // Sorting by a hidden price would still reveal the price order.
            if (!$showPrices && $this->toMagentoSortCode($option['value']) === 'price') {
                continue;
            }
            $options[] = ['label' => (string) __($option['label']), 'value' => $option['value']];
        }

        $available = $category?->getAvailableSortBy();
        if (!$available) {
            return $options;
        }

        $allowed = array_values(array_filter(
            $options,
            fn (array $option): bool => in_array($this->toMagentoSortCode($option['value']), $available, true),
        ));

        // A category whose sort attributes match none of the options keeps them all rather than an empty menu.
        return $allowed !== [] ? $allowed : $options;
    }

    private function toMagentoSortCode(string $sortValue): string
    {
        if ($sortValue === '') {
            return 'position';
        }

        return explode(':', $sortValue, 2)[0];
    }

    private function mapMagentoSort(string $sortBy): ?string
    {
        return self::MAGENTO_SORT_MAP[$sortBy] ?? null;
    }

    /** @return list<int> */
    private function getCatalogLimits(int $storeId): array
    {
        $value = (string) $this->scopeConfig->getValue(
            'catalog/frontend/grid_per_page_values',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );

        $limits = [];
        $parts = explode(',', $value);
        foreach ($parts as $part) {
            $limit = (int) trim($part);
            if ($limit > 0 && !in_array($limit, $limits, true)) {
                $limits[] = $limit;
            }
        }

        return $limits;
    }

    /** @param list<int> $limits */
    private function getPerPage(bool $isCategory, array $limits, int $storeId): int
    {
        $searchPerPage = $isCategory ? 0 : $this->config->getProductsPerPage($storeId);
        if ($searchPerPage > 0) {
            return $searchPerPage;
        }

        $catalogPerPage = (int) $this->scopeConfig->getValue(
            'catalog/frontend/grid_per_page',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );

        return $catalogPerPage > 0 ? $catalogPerPage : ($limits[0] ?? 12);
    }
}
