<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\SalesCountResolver;

final class SalesCountResolverTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private SalesCountResolver $sut;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->with('sales_bestsellers_aggregated_daily')
            ->willReturn('sales_bestsellers_aggregated_daily');

        $this->sut = new SalesCountResolver($this->resourceConnection);
    }

    public function test_get_sales_count_returns_zero_when_table_does_not_exist(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $result = $this->sut->getSalesCount(1);

        self::assertSame(0, $result);
    }

    public function test_get_sales_count_returns_zero_for_unknown_product(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([
            ['product_id' => '5', 'qty_ordered' => '10'],
        ]);

        $result = $this->sut->getSalesCount(999);

        self::assertSame(0, $result);
    }

    public function test_get_sales_count_returns_aggregated_qty_for_known_product(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([
            ['product_id' => '5', 'qty_ordered' => '25'],
            ['product_id' => '7', 'qty_ordered' => '8'],
        ]);

        $result = $this->sut->getSalesCount(5);

        self::assertSame(25, $result);
    }

    public function test_get_sales_count_loads_all_only_once(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::once())->method('fetchAll')->willReturn([
            ['product_id' => '1', 'qty_ordered' => '3'],
        ]);

        $this->sut->getSalesCount(1);
        $this->sut->getSalesCount(1);
    }
}
