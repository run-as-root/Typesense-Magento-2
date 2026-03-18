<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\BatchImportService;

final class BatchImportServiceTest extends TestCase
{
    public function test_chunk_documents_splits_by_batch_size(): void
    {
        $documents = [
            ['id' => '1', 'name' => 'Product 1'],
            ['id' => '2', 'name' => 'Product 2'],
            ['id' => '3', 'name' => 'Product 3'],
            ['id' => '4', 'name' => 'Product 4'],
            ['id' => '5', 'name' => 'Product 5'],
        ];

        $chunks = BatchImportService::chunkIterable($documents, 2);
        $result = iterator_to_array($chunks);

        self::assertCount(3, $result);
        self::assertCount(2, $result[0]);
        self::assertCount(2, $result[1]);
        self::assertCount(1, $result[2]);
    }

    public function test_chunk_documents_handles_exact_batch_size(): void
    {
        $documents = [
            ['id' => '1'],
            ['id' => '2'],
            ['id' => '3'],
            ['id' => '4'],
        ];

        $chunks = BatchImportService::chunkIterable($documents, 2);
        $result = iterator_to_array($chunks);

        self::assertCount(2, $result);
        self::assertCount(2, $result[0]);
        self::assertCount(2, $result[1]);
    }

    public function test_chunk_documents_handles_empty_iterable(): void
    {
        $chunks = BatchImportService::chunkIterable([], 10);
        $result = iterator_to_array($chunks);

        self::assertCount(0, $result);
    }

    public function test_chunk_documents_works_with_generator(): void
    {
        $generator = function (): \Generator {
            for ($i = 1; $i <= 7; $i++) {
                yield ['id' => (string) $i];
            }
        };

        $chunks = BatchImportService::chunkIterable($generator(), 3);
        $result = iterator_to_array($chunks);

        self::assertCount(3, $result);
        self::assertCount(3, $result[0]);
        self::assertCount(3, $result[1]);
        self::assertCount(1, $result[2]);
    }
}
