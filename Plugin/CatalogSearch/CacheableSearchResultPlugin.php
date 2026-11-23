<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Plugin\CatalogSearch;

use Magento\Catalog\Model\Layer\Resolver;
use Magento\CatalogSearch\Controller\Result\Index;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Search\Model\QueryFactory;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * The search page is a static shell whose results come from Typesense in the browser. Skipping the core action
 * avoids the popularity write and the no-cache headers, so the full page cache can store one page per query.
 */
class CacheableSearchResultPlugin
{
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
        if ($queryText === '') {
            return $proceed();
        }

        // Search terms with a redirect URL (Marketing > Search Terms) keep the core redirect.
        if ((string) $this->queryFactory->get()->getRedirect() !== '') {
            return $proceed();
        }

        $this->layerResolver->create(Resolver::CATALOG_LAYER_SEARCH);

        return $this->pageFactory->create();
    }
}
