<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Plugin\Catalog;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\View\LayoutInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use WeakMap;

/**
 * Typesense renders the listing in the browser, so on those pages every ListProduct gets an empty collection
 * before anything can build or load the layer collection. The getter, _beforeToHtml() (via toHtml()) and
 * getIdentities() (FPC tags) are the public entry points that reach the protected _getProductCollection().
 */
class SkipProductCollectionPlugin
{
    private const string CATEGORY_HANDLE = 'typesense_category_search';
    private const string SEARCH_HANDLE = 'catalogsearch_result_index';

    /** @var WeakMap<ListProduct, Collection> */
    private WeakMap $emptyCollections;

    public function __construct(
        private readonly LayoutInterface $layout,
        private readonly TypeSenseConfigInterface $config,
        private readonly CollectionFactory $collectionFactory,
    ) {
        $this->emptyCollections = new WeakMap();
    }

    public function beforeGetLoadedProductCollection(ListProduct $subject): void
    {
        $this->skipCollection($subject);
    }

    public function beforeToHtml(ListProduct $subject): void
    {
        $this->skipCollection($subject);
    }

    public function beforeGetIdentities(ListProduct $subject): void
    {
        $this->skipCollection($subject);
    }

    private function skipCollection(ListProduct $subject): void
    {
        if (isset($this->emptyCollections[$subject]) || !$this->isTypesenseListingPage()) {
            return;
        }

        $collection = $this->collectionFactory->create();
        $collection->setPageSize(0);
        // The filter empties the collection; a page size of 0 means "no limit" to Magento.
        $collection->addFieldToFilter('entity_id', ['eq' => 0]);
        $subject->setCollection($collection);
        $this->emptyCollections[$subject] = $collection;
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
