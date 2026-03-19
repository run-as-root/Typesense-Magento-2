<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\ReviewResolver;

final class ReviewResolverTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private ReviewResolver $sut;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->with('review_entity_summary')
            ->willReturn('review_entity_summary');

        $this->sut = new ReviewResolver($this->resourceConnection);
    }

    public function test_get_rating_summary_returns_zero_when_table_does_not_exist(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $result = $this->sut->getRatingSummary(1, 1);

        self::assertSame(0, $result);
    }

    public function test_get_review_count_returns_zero_when_table_does_not_exist(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $result = $this->sut->getReviewCount(1, 1);

        self::assertSame(0, $result);
    }

    public function test_get_rating_summary_returns_zero_for_unknown_product(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([
            ['entity_pk_value' => '5', 'rating_summary' => '80', 'reviews_count' => '10'],
        ]);

        $result = $this->sut->getRatingSummary(999, 1);

        self::assertSame(0, $result);
    }

    public function test_get_rating_summary_returns_correct_value_for_known_product(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([
            ['entity_pk_value' => '5', 'rating_summary' => '80', 'reviews_count' => '10'],
            ['entity_pk_value' => '7', 'rating_summary' => '60', 'reviews_count' => '3'],
        ]);

        $result = $this->sut->getRatingSummary(5, 1);

        self::assertSame(80, $result);
    }

    public function test_get_review_count_returns_correct_value_for_known_product(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([
            ['entity_pk_value' => '5', 'rating_summary' => '80', 'reviews_count' => '12'],
        ]);

        $result = $this->sut->getReviewCount(5, 1);

        self::assertSame(12, $result);
    }

    public function test_ensure_loaded_queries_each_store_once(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::exactly(2))->method('fetchAll')->willReturn([]);

        // Store 1 — two calls, should only trigger one DB query
        $this->sut->getRatingSummary(1, 1);
        $this->sut->getReviewCount(1, 1);

        // Store 2 — new store, triggers a second DB query
        $this->sut->getRatingSummary(1, 2);
    }
}
