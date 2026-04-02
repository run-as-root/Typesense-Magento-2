<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\QueryCustomersTool;

final class QueryCustomersToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private AdapterInterface&MockObject $connection;
    private QueryCustomersTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnCallback(
            static fn(string $table) => $table
        );

        $this->sut = new QueryCustomersTool($this->resource);
    }

    public function test_get_name_returns_query_customers(): void
    {
        self::assertSame('query_customers', $this->sut->getName());
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

        self::assertContains('count_by_country', $enum);
        self::assertContains('count_by_group', $enum);
        self::assertContains('top_by_lifetime_value', $enum);
        self::assertContains('top_by_order_count', $enum);
    }

    public function test_execute_count_by_country_queries_customer_address(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(self::stringContainsString('customer_address_entity'))
            ->willReturn([['country_id' => 'DE', 'customer_count' => '150']]);

        $result = json_decode($this->sut->execute(['aggregation' => 'count_by_country']), true);

        self::assertSame('count_by_country', $result['aggregation']);
        self::assertCount(1, $result['rows']);
    }

    public function test_execute_top_by_lifetime_value_joins_sales_order(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(self::stringContainsString('sales_order'))
            ->willReturn([
                ['email' => 'john@example.com', 'lifetime_value' => '9999.00', 'order_count' => '12'],
            ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'top_by_lifetime_value']), true);

        self::assertSame('top_by_lifetime_value', $result['aggregation']);
        self::assertCount(1, $result['rows']);
    }

    public function test_execute_returns_error_for_unknown_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'unknown']), true);

        self::assertArrayHasKey('error', $result);
    }
}
