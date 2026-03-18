<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;

final class EntityIndexerPoolTest extends TestCase
{
    public function test_get_indexer_returns_registered_indexer(): void
    {
        $mockIndexer = $this->createMock(EntityIndexerInterface::class);
        $mockIndexer->method('getEntityType')->willReturn('product');

        $pool = new EntityIndexerPool(['product' => $mockIndexer]);

        self::assertSame($mockIndexer, $pool->getIndexer('product'));
    }

    public function test_get_indexer_throws_for_unknown_entity(): void
    {
        $pool = new EntityIndexerPool([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No indexer registered for entity type: unknown');
        $pool->getIndexer('unknown');
    }

    public function test_get_all_returns_all_indexers(): void
    {
        $product = $this->createMock(EntityIndexerInterface::class);
        $category = $this->createMock(EntityIndexerInterface::class);

        $pool = new EntityIndexerPool(['product' => $product, 'category' => $category]);

        self::assertCount(2, $pool->getAll());
    }

    public function test_has_indexer_returns_true_for_registered(): void
    {
        $mockIndexer = $this->createMock(EntityIndexerInterface::class);
        $pool = new EntityIndexerPool(['product' => $mockIndexer]);

        self::assertTrue($pool->hasIndexer('product'));
        self::assertFalse($pool->hasIndexer('nonexistent'));
    }
}
