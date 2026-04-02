<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\QueryOrdersTool;

final class QueryOrdersToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private AdapterInterface&MockObject $connection;
    private QueryOrdersTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnCallback(
            static fn(string $table) => $table
        );

        $this->sut = new QueryOrdersTool($this->resource);
    }

    public function test_get_name_returns_query_orders(): void
    {
        self::assertSame('query_orders', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_required_aggregation(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('aggregation', $schema['properties']);
        self::assertContains('aggregation', $schema['required']);
    }

    public function test_get_parameters_schema_aggregation_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $enum = $schema['properties']['aggregation']['enum'];

        self::assertContains('total_revenue', $enum);
        self::assertContains('revenue_by_country', $enum);
        self::assertContains('top_customers_by_revenue', $enum);
        self::assertContains('order_count_by_status', $enum);
        self::assertContains('avg_order_value', $enum);
        self::assertContains('orders_by_month', $enum);
    }

    public function test_execute_total_revenue_fetches_from_sales_order(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::stringContainsString('sales_order'),
                self::anything()
            )
            ->willReturn([['total' => '1234.56', 'currency' => 'EUR']]);

        $result = json_decode($this->sut->execute(['aggregation' => 'total_revenue']), true);

        self::assertSame('total_revenue', $result['aggregation']);
        self::assertCount(1, $result['rows']);
    }

    public function test_execute_returns_error_for_unknown_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'invalid_agg']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('invalid_agg', $result['error']);
    }

    public function test_execute_order_count_by_status_groups_by_status(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(self::stringContainsString('status'), self::anything())
            ->willReturn([
                ['status' => 'complete', 'count' => '42'],
                ['status' => 'pending', 'count' => '7'],
            ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'order_count_by_status']), true);

        self::assertSame('order_count_by_status', $result['aggregation']);
        self::assertCount(2, $result['rows']);
    }

    public function test_execute_filters_by_status_when_provided(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::stringContainsString('so.status = :status'),
                self::arrayHasKey(':status')
            )
            ->willReturn([]);

        $this->sut->execute(['aggregation' => 'total_revenue', 'status' => 'complete']);
    }

    public function test_execute_filters_by_date_from_when_provided(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::stringContainsString('so.created_at >= :date_from'),
                self::arrayHasKey(':date_from')
            )
            ->willReturn([]);

        $this->sut->execute(['aggregation' => 'total_revenue', 'date_from' => '2025-01-01']);
    }
}
