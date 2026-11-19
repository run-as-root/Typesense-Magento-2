<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\ParentIdResolver;

final class ParentIdResolverTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private AdapterInterface&MockObject $connection;
    private ParentIdResolver $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new ParentIdResolver($this->resource);
    }

    public function test_empty_input_does_not_query(): void
    {
        $this->resource->expects(self::never())->method('getConnection');

        self::assertSame([], $this->sut->getParentIds([]));
    }

    public function test_returns_unique_integer_parent_ids(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->with('catalog_product_relation', ['parent_id'])->willReturnSelf();
        $select->method('where')->with('child_id IN (?)', [101, 102])->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchCol')->with($select)->willReturn(['42', '42', '43']);

        self::assertSame([42, 43], $this->sut->getParentIds([101, 102]));
    }
}
