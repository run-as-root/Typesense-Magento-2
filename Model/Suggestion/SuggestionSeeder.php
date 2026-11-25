<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Suggestion;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\BatchImportService;

/**
 * Carries a store's existing Magento search history into its suggestion collection, which Typesense's
 * popular-queries analytics maintain from then on.
 */
class SuggestionSeeder
{
    private const INT32_MAX = 2147483647;
    // Typesense analytics ignore longer queries (QueryAnalytics::max_query_length).
    private const MAX_QUERY_LENGTH = 1024;
    private const HTTP_CONFLICT = 409;

    public function __construct(
        private readonly SearchQueryReader $reader,
        private readonly AnalyticsQueryKey $queryKey,
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly TypeSenseConfigInterface $config,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Creates documents only for terms the collection doesn't hold yet, so the counts Typesense added are never
     * overwritten and a repeated seed changes nothing. More terms than the rule keeps would be trimmed by
     * Typesense on its next flush, so the limit is capped at the configured suggestion limit.
     *
     * @return array{total: int, imported: int, existing: int, failed: int}
     */
    public function seed(int $storeId, string $storeCode, ?int $limit = null): array
    {
        $ruleLimit = $this->config->getSuggestionLimit($storeId);
        $rows = $this->reader->getTopQueries($storeId, min($limit ?? $ruleLimit, $ruleLimit));
        $documents = $this->buildDocuments($rows);
        $stats = ['total' => 0, 'imported' => 0, 'existing' => 0, 'failed' => 0];
        if ($documents === []) {
            return $stats;
        }

        $collectionName = $this->nameResolver->resolve('suggestion', $storeCode, $storeId);
        $collection = $this->clientFactory->create($storeId)->collections[$collectionName];
        $batches = BatchImportService::chunkIterable($documents, max(1, $this->config->getBatchSize($storeId)));

        foreach ($batches as $batch) {
            $results = $collection->documents->import($batch, ['action' => 'create']);
            $this->tally($results, $collectionName, $stats);
        }

        return $stats;
    }

    /**
     * @param list<array{query: string, popularity: int}> $rows
     * @return list<array{id: string, q: string, count: int}>
     */
    private function buildDocuments(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $query = $this->queryKey->normalize($row['query']);
            // typesense-php encodes documents with JSON_THROW_ON_ERROR: one malformed term would abort the import.
            if ($query === '' || $query === '*' || strlen($query) >= self::MAX_QUERY_LENGTH
                || !mb_check_encoding($query, 'UTF-8')
            ) {
                continue;
            }
            $counts[$query] = ($counts[$query] ?? 0) + $row['popularity'];
        }

        $documents = [];
        foreach ($counts as $query => $count) {
            // Numeric terms such as "2024" become integer array keys.
            $query = (string) $query;
            $documents[] = [
                'id' => $this->queryKey->documentId($query),
                'q' => $query,
                'count' => min($count, self::INT32_MAX),
            ];
        }

        return $documents;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @param array{total: int, imported: int, existing: int, failed: int} $stats
     */
    private function tally(array $results, string $collectionName, array &$stats): void
    {
        foreach ($results as $result) {
            $stats['total']++;
            if (($result['success'] ?? false) === true) {
                $stats['imported']++;
                continue;
            }
            if ((int) ($result['code'] ?? 0) === self::HTTP_CONFLICT) {
                $stats['existing']++;
                continue;
            }
            $stats['failed']++;
            $this->logger->warning('Suggestion seed document failed', [
                'collection' => $collectionName,
                'error' => $result['error'] ?? 'unknown',
            ]);
        }
    }
}
