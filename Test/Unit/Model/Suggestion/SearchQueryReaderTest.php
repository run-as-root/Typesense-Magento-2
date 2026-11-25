<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Suggestion;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Suggestion\SearchQueryReader;

final class SearchQueryReaderTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    /** @var array<int, array{string, mixed}> */
    private array $conditions = [];
    private SearchQueryReader $sut;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('order')->willReturnSelf();
        $this->select->method('limit')->willReturnSelf();
        $this->select->method('where')->willReturnCallback(function (string $condition, mixed $value = null): Select {
            $this->conditions[] = [$condition, $value];

            return $this->select;
        });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        $this->sut = new SearchQueryReader($resource);
    }

    public function test_reads_the_most_popular_active_terms_with_results_in_one_limited_query(): void
    {
        $this->connection->method('tableColumnExists')->with('pfx_search_query', 'is_active')->willReturn(true);
        $this->connection->expects(self::once())->method('fetchAll')->with($this->select)->willReturn([
            ['query_text' => 'Birne', 'popularity' => '42'],
            ['query_text' => 'gin', 'popularity' => '7'],
        ]);

        $this->select->expects(self::once())->method('from')->with('pfx_search_query', ['query_text', 'popularity']);
        $this->select->expects(self::once())->method('order')->with(['popularity DESC', 'query_id ASC']);
        $this->select->expects(self::once())->method('limit')->with(50);

        $rows = $this->sut->getTopQueries(3, 50);

        self::assertSame(
            [['store_id = ?', 3], ['num_results > 0', null], ['is_active = ?', 1]],
            $this->conditions,
        );
        self::assertSame([['query' => 'Birne', 'popularity' => 42], ['query' => 'gin', 'popularity' => 7]], $rows);
    }

    public function test_skips_the_active_filter_when_the_column_does_not_exist(): void
    {
        $this->connection->method('tableColumnExists')->willReturn(false);
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertSame([], $this->sut->getTopQueries(3, 50));
        self::assertSame([['store_id = ?', 3], ['num_results > 0', null]], $this->conditions);
    }

    public function test_a_limit_below_one_reads_nothing(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->sut->getTopQueries(3, 0));
    }
}
