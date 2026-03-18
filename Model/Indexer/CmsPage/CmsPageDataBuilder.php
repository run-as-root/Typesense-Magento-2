<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\CmsPage;

use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\Collection as PageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;

class CmsPageDataBuilder
{
    public function __construct(
        private readonly PageCollectionFactory $collectionFactory,
    ) {
    }

    /**
     * Build a Typesense document array from a CMS page.
     *
     * @return array<string, mixed>
     */
    public function build(Page $page, int $storeId): array
    {
        $pageId = (int) $page->getId();

        return [
            'id' => (string) $pageId,
            'page_id' => $pageId,
            'title' => (string) $page->getTitle(),
            'content' => strip_tags((string) $page->getContent()),
            'url_key' => (string) $page->getIdentifier(),
            'is_active' => (bool) $page->isActive(),
        ];
    }

    /**
     * Load a CMS page collection filtered by entity IDs, scoped to a store.
     *
     * @param int[] $entityIds
     */
    public function getPageCollection(array $entityIds, int $storeId): PageCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('is_active', 1);

        if ($entityIds !== []) {
            $collection->addFieldToFilter('page_id', ['in' => $entityIds]);
        }

        return $collection;
    }
}
