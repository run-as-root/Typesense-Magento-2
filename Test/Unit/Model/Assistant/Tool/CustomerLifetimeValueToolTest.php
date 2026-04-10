<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\CustomerLifetimeValueTool;

final class CustomerLifetimeValueToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private CustomerLifetimeValueTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new CustomerLifetimeValueTool($this->resource);
    }

    public function test_get_name_returns_customer_lifetime_value(): void
    {
        self::assertSame('customer_lifetime_value', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_required_fields(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('required', $schema);
        self::assertContains('aggregation', $schema['required']);
    }

    public function test_get_parameters_schema_aggregation_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $aggregationProp = $schema['properties']['aggregation'];

        self::assertArrayHasKey('enum', $aggregationProp);
        self::assertContains('top_customers', $aggregationProp['enum']);
        self::assertContains('by_first_product', $aggregationProp['enum']);
        self::assertContains('by_acquisition_month', $aggregationProp['enum']);
        self::assertContains('average_ltv', $aggregationProp['enum']);
        self::assertContains('projected_ltv', $aggregationProp['enum']);
    }

    public function test_get_parameters_schema_has_limit_property(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertSame('integer', $schema['properties']['limit']['type']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'invalid']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_top_customers_returns_rows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '42',
                'customer_email' => 'jane@example.com',
                'customer_firstname' => 'Jane',
                'customer_lastname' => 'Doe',
                'total_spend' => '1500.00',
                'order_count' => '3',
                'avg_order_value' => '500.00',
                'first_order_date' => '2024-01-15 10:00:00',
                'last_order_date' => '2025-03-01 09:00:00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'top_customers']), true);

        self::assertSame('top_customers', $result['aggregation']);
        self::assertCount(1, $result['rows']);
        self::assertSame(42, $result['rows'][0]['customer_id']);
        self::assertSame('jane@example.com', $result['rows'][0]['email']);
        self::assertSame('Jane Doe', $result['rows'][0]['name']);
        self::assertSame(1500.0, $result['rows'][0]['total_spend']);
    }

    public function test_execute_average_ltv_returns_summary(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'total_customers' => '200',
            'avg_ltv' => '350.75',
            'min_ltv' => '25.00',
            'max_ltv' => '5000.00',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'average_ltv']), true);

        self::assertSame('average_ltv', $result['aggregation']);
        self::assertSame(200, $result['total_customers']);
        self::assertSame(350.75, $result['avg_ltv']);
    }

    public function test_execute_projected_ltv_returns_error_when_no_data(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchRow')->willReturn([
            'total_orders' => '0',
            'total_customers' => '0',
            'years_of_data' => '0',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'projected_ltv']), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute(['aggregation' => 'top_customers']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
