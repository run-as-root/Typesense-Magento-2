<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\CmsPage;

use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\Collection as PageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Psr\Log\LoggerInterface;

class CmsPageDataBuilder
{
    public function __construct(
        private readonly PageCollectionFactory $collectionFactory,
        private readonly LoggerInterface $logger,
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
            'content' => $this->toText($this->getContent($page)),
            'url_key' => (string) $page->getIdentifier(),
            'is_active' => (bool) $page->isActive(),
        ];
    }

    /**
     * getContent() runs content plugins (e.g. Hyvä CMS renders its components in the frontend area), and one
     * broken component must not fail the whole reindex: the page then falls back to its stored content.
     */
    private function getContent(Page $page): string
    {
        try {
            return (string) $page->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('TypeSense: rendering CMS page %d failed, indexing its stored content: %s', (int) $page->getId(), $e->getMessage()),
                ['exception' => $e],
            );

            return (string) $page->getData('content');
        }
    }

    private function toText(string $html): string
    {
        $withoutCode = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($withoutCode)));
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
