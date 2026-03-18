<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class BatchImportService
{
    public function __construct(
        private readonly TypeSenseClientFactory $clientFactory,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Import documents into a Typesense collection in batches.
     *
     * @param iterable<array<string, mixed>> $documents
     * @return array{total: int, success: int, failed: int}
     */
    public function import(string $collectionName, iterable $documents, ?int $storeId = null): array
    {
        $batchSize = $this->config->getBatchSize($storeId);
        $client = $this->clientFactory->create($storeId);
        $total = 0;
        $success = 0;
        $failed = 0;

        foreach (self::chunkIterable($documents, $batchSize) as $batch) {
            $results = $client->collections[$collectionName]->documents->import($batch, ['action' => 'upsert']);

            foreach ($results as $result) {
                $total++;
                if (($result['success'] ?? false) === true) {
                    $success++;
                } else {
                    $failed++;
                    $this->logger->warning('Document import failed', [
                        'collection' => $collectionName,
                        'error' => $result['error'] ?? 'unknown',
                        'document' => $result['document'] ?? '',
                    ]);
                }
            }
        }

        $this->logger->info("Import complete for {$collectionName}: {$success}/{$total} succeeded, {$failed} failed");

        return ['total' => $total, 'success' => $success, 'failed' => $failed];
    }

    /**
     * Chunk an iterable into arrays of $size.
     *
     * @template T
     * @param iterable<T> $iterable
     * @param positive-int $size
     * @return \Generator<int, list<T>>
     */
    public static function chunkIterable(iterable $iterable, int $size): \Generator
    {
        $batch = [];

        foreach ($iterable as $item) {
            $batch[] = $item;

            if (count($batch) === $size) {
                yield $batch;
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }
}
