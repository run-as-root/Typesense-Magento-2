<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Plugin\CatalogSearch;

use Magento\Catalog\Model\Layer\Resolver;
use Magento\CatalogSearch\Controller\Result\Index;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Search\Model\QueryFactory;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetResolver;

/**
 * The search page is a static shell whose results come from Typesense in the browser. Skipping the core action
 * avoids the popularity write and the no-cache headers, so the full page cache can store one page per query;
 * the handle makes the page log the term from the browser instead.
 */
class CacheableSearchResultPlugin
{
    public const string CACHEABLE_PAGE_HANDLE = 'typesense_cacheable_search_result';
    private const array LISTING_STATE_PARAMS = ['q', 'page', 'sort', 'limit', 'price', CategoryFacetResolver::URL_PARAM];

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly RequestInterface $request,
        private readonly Resolver $layerResolver,
        private readonly PageFactory $pageFactory,
        private readonly QueryFactory $queryFactory,
    ) {
    }

    public function aroundExecute(Index $subject, callable $proceed): mixed
    {
        if (!$this->config->isEnabled()
            || !$this->config->isInstantSearchEnabled()
            || !$this->config->isCacheableSearchPageEnabled()
        ) {
            return $proceed();
        }

        $queryText = trim((string) $this->request->getParam(QueryFactory::QUERY_VAR_NAME));
        if ($queryText === '' || !$this->hasOnlyListingStateParams()) {
            return $proceed();
        }

        // Search terms with a redirect URL (Marketing > Search Terms) keep the core redirect.
        if ((string) $this->queryFactory->get()->getRedirect() !== '') {
            return $proceed();
        }

        $this->layerResolver->create(Resolver::CATALOG_LAYER_SEARCH);
        $page = $this->pageFactory->create();
        $page->addHandle(self::CACHEABLE_PAGE_HANDLE);

        return $page;
    }

    // Each extra param would be its own cache entry, so unknown params get the uncached core page.
    private function hasOnlyListingStateParams(): bool
    {
        $facetFields = $this->config->getFacetFilters();
        // PHP turns dots in query keys into underscores (is.new -> is_new).
        $facetParams = str_replace('.', '_', $facetFields);
        $allowed = array_merge(self::LISTING_STATE_PARAMS, $facetFields, $facetParams);
        $params = array_map('strval', array_keys($this->request->getParams()));

        return array_diff($params, $allowed) === [];
    }
}
