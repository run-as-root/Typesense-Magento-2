<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use Magento\Framework\App\Cache\TypeListInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Listing\CollectionFieldProvider;

/**
 * Cached pages embed the search parameters (query_by, sort_by, facets) derived from the schema they were rendered
 * against, so they must not outlive a schema change.
 */
class SchemaChangeHandler
{
    private const string FULL_PAGE_CACHE_TYPE = 'full_page';

    public function __construct(
        private readonly CollectionFieldProvider $fieldProvider,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $collections aliases (or plain collection names) whose schema changed
     */
    public function handle(array $collections): void
    {
        if ($collections === []) {
            return;
        }

        foreach ($collections as $collection) {
            $this->fieldProvider->invalidate($collection);
        }

        try {
            $this->cacheTypeList->cleanType(self::FULL_PAGE_CACHE_TYPE);
            $this->logger->info('Cleaned the full page cache after a schema change of ' . implode(', ', $collections));
        } catch (\Throwable $e) {
            // The reindex itself succeeded; a cache backend failure must not report it as failed.
            $this->logger->warning(
                'Could not clean the full page cache after a schema change: ' . $e->getMessage(),
                ['exception' => $e],
            );
        }
    }
}
