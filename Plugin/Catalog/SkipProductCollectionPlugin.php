<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Plugin\Catalog;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Data\Collection;
use Magento\Framework\View\LayoutInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * Typesense renders the listing in the browser, so blocks that still read the layer collection on those pages
 * (toolbars, structured data, analytics) get an empty one instead of triggering the Magento product query.
 */
class SkipProductCollectionPlugin
{
    public const string EMPTY_COLLECTION_FLAG = 'run_as_root_typesense_skipped';
    private const string CATEGORY_HANDLE = 'typesense_category_search';
    private const string SEARCH_HANDLE = 'catalogsearch_result_index';

    public function __construct(
        private readonly LayoutInterface $layout,
        private readonly TypeSenseConfigInterface $config,
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    public function afterGetLoadedProductCollection(ListProduct $subject, Collection $result): Collection
    {
        if ($result->getFlag(self::EMPTY_COLLECTION_FLAG) || !$this->isTypesenseListingPage()) {
            return $result;
        }

        $collection = $this->collectionFactory->create();
        $collection->setPageSize(0);
        $collection->addFieldToFilter('entity_id', ['eq' => 0]);
        $collection->setFlag(self::EMPTY_COLLECTION_FLAG, true);
        // _beforeToHtml(), getIdentities() and the price renderer read the protected collection directly.
        $subject->setCollection($collection);

        return $collection;
    }

    private function isTypesenseListingPage(): bool
    {
        $handles = $this->layout->getUpdate()->getHandles();

        if (in_array(self::CATEGORY_HANDLE, $handles, true)) {
            return true;
        }

        return in_array(self::SEARCH_HANDLE, $handles, true)
            && $this->config->isEnabled()
            && $this->config->isInstantSearchEnabled();
    }
}
