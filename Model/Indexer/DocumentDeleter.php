<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;
use Typesense\Exceptions\ObjectNotFound;

class DocumentDeleter
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly TypeSenseClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int|string> $ids
     */
    public function deleteByIds(string $collectionName, array $ids, ?int $storeId = null): int
    {
        if ($ids === []) {
            return 0;
        }

        $client = $this->clientFactory->create($storeId);
        $deleted = 0;

        foreach (array_chunk(array_values($ids), self::CHUNK_SIZE) as $chunk) {
            $filter = 'id:[' . implode(',', $chunk) . ']';

            try {
                $result = $client->collections[$collectionName]->documents->delete(['filter_by' => $filter]);
            } catch (ObjectNotFound) {
                $this->logger->debug("Collection {$collectionName} not found, nothing to delete");
                return $deleted;
            }

            $deleted += (int) ($result['num_deleted'] ?? 0);
        }

        $this->logger->info("Deleted {$deleted} stale documents from {$collectionName}");

        return $deleted;
    }
}
