<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Suggestion;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\BatchImportService;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;
use Typesense\Client;
use Typesense\Documents;

/**
 * Typesense counts whatever visitors send, and its popular-queries rule neither checks results nor content. The
 * pruner deletes terms the shop must never show (SuggestionTermFilter) and flags the others `is_valid` by whether
 * the storefront search finds products for them; the storefront only shows valid ones. New terms stay hidden until
 * their first check.
 */
class SuggestionPruner
{
    private const PAGE_SIZE = 250;
    // Typesense's default limit_multi_searches.
    private const SEARCHES_PER_REQUEST = 50;
    private const DELETES_PER_REQUEST = 250;
    // The search page's visibility filter: a term is valid when the search page lists something for it.
    private const VISIBILITY_FILTER = 'visibility:[3,4]';

    public function __construct(
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly TypeSenseConfigInterface $config,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly SuggestionTermFilter $termFilter,
        private readonly SearchRequestParamsBuilder $requestParamsBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Checks at most the rule limit of documents: Typesense keeps no more than that after each flush.
     *
     * @return array{checked: int, valid: int, invalid: int, deleted: int}
     */
    public function prune(int $storeId, string $storeCode): array
    {
        $client = $this->clientFactory->create($storeId);
        $collectionName = $this->nameResolver->resolve('suggestion', $storeCode, $storeId);
        $documents = $client->collections[$collectionName]->documents;
        $suggestions = $this->readSuggestions($documents, $this->config->getSuggestionLimit($storeId));

        $blockedIds = [];
        $candidates = [];
        foreach ($suggestions as $suggestion) {
            if ($this->termFilter->isAllowed($suggestion['q'], $storeId)) {
                $candidates[] = $suggestion;
            } else {
                $blockedIds[] = $suggestion['id'];
            }
        }

        $deleteBatches = array_chunk($blockedIds, self::DELETES_PER_REQUEST);
        foreach ($deleteBatches as $ids) {
            $documents->delete(['filter_by' => 'id:[' . implode(',', $ids) . ']']);
        }

        $stats = ['checked' => count($suggestions), 'valid' => 0, 'invalid' => 0, 'deleted' => count($blockedIds)];
        $updates = $this->checkResults($client, $candidates, $storeId, $storeCode, $stats);
        $updateBatches = BatchImportService::chunkIterable($updates, max(1, $this->config->getBatchSize($storeId)));
        foreach ($updateBatches as $batch) {
            $results = $documents->import($batch, ['action' => 'update']);
            $this->logFailures($results, $collectionName);
        }

        $this->logger->info(sprintf(
            'Pruned suggestions %s: %d checked, %d valid, %d without results, %d deleted',
            $collectionName,
            $stats['checked'],
            $stats['valid'],
            $stats['invalid'],
            $stats['deleted'],
        ));

        return $stats;
    }

    /**
     * @return list<array{id: string, q: string, is_valid: ?bool}>
     */
    private function readSuggestions(Documents $documents, int $limit): array
    {
        $suggestions = [];
        // Pages must keep one size, or page n no longer starts after page n-1.
        $pageSize = min(self::PAGE_SIZE, $limit);
        for ($page = 1; count($suggestions) < $limit; $page++) {
            $result = $documents->search([
                'q' => '*',
                'query_by' => 'q',
                'sort_by' => 'count:desc',
                'include_fields' => 'id,q,is_valid',
                'per_page' => $pageSize,
                'page' => $page,
                'enable_analytics' => false,
            ]);
            $hits = $result['hits'] ?? [];
            foreach ($hits as $hit) {
                $document = $hit['document'] ?? [];
                $suggestions[] = [
                    'id' => (string) ($document['id'] ?? ''),
                    'q' => (string) ($document['q'] ?? ''),
                    'is_valid' => isset($document['is_valid']) ? (bool) $document['is_valid'] : null,
                ];
            }
            if (count($hits) < $pageSize) {
                break;
            }
        }

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * @param list<array{id: string, q: string, is_valid: ?bool}> $candidates
     * @param array{checked: int, valid: int, invalid: int, deleted: int} $stats
     * @return list<array{id: string, is_valid: bool}> Documents whose flag changed
     */
    private function checkResults(
        Client $client,
        array $candidates,
        int $storeId,
        string $storeCode,
        array &$stats,
    ): array {
        $base = array_merge($this->requestParamsBuilder->build(SearchRequestParamsBuilder::CONTEXT_SEARCH, $storeId), [
            'collection' => $this->nameResolver->resolve('product', $storeCode, $storeId),
        ]);
        $updates = [];
        $batches = array_chunk($candidates, self::SEARCHES_PER_REQUEST);

        foreach ($batches as $batch) {
            $searches = array_map(static fn (array $suggestion): array => array_merge($base, [
                'q' => $suggestion['q'],
                'filter_by' => self::VISIBILITY_FILTER,
                'per_page' => 0,
                'enable_analytics' => false,
            ]), $batch);
            $response = $client->multiSearch->perform(['searches' => $searches]);
            $results = $response['results'] ?? [];

            foreach ($batch as $index => $suggestion) {
                $result = $results[$index] ?? null;
                // An unanswered check must not hide a term that was fine before, nor show one that wasn't.
                if (!is_array($result) || !isset($result['found'])) {
                    continue;
                }
                $valid = (int) $result['found'] > 0;
                $stats[$valid ? 'valid' : 'invalid']++;
                if ($suggestion['is_valid'] !== $valid) {
                    $updates[] = ['id' => $suggestion['id'], 'is_valid' => $valid];
                }
            }
        }

        return $updates;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function logFailures(array $results, string $collectionName): void
    {
        foreach ($results as $result) {
            if (($result['success'] ?? false) !== true) {
                $this->logger->warning('Suggestion flag update failed', [
                    'collection' => $collectionName,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        }
    }
}
