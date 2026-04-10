<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\CustomerConcentrationRiskTool;

final class CustomerConcentrationRiskToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private CustomerConcentrationRiskTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new CustomerConcentrationRiskTool($this->resource);
    }

    public function test_get_name_returns_customer_concentration_risk(): void
    {
        self::assertSame('customer_concentration_risk', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_required_aggregation(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertContains('aggregation', $schema['required']);
    }

    public function test_get_parameters_schema_aggregation_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $aggregationProp = $schema['properties']['aggregation'];

        self::assertArrayHasKey('enum', $aggregationProp);
        self::assertContains('pareto_analysis', $aggregationProp['enum']);
        self::assertContains('concentration_trend', $aggregationProp['enum']);
        self::assertContains('top_customer_dependency', $aggregationProp['enum']);
    }

    public function test_get_parameters_schema_has_top_percentage_property(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('top_percentage', $schema['properties']);
        self::assertSame('integer', $schema['properties']['top_percentage']['type']);
        self::assertSame(10, $schema['properties']['top_percentage']['default']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'bad_agg']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_pareto_analysis_calculates_revenue_concentration(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // fetchRow is called twice: once for totals, once for top revenue
        $connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['total_customers' => '100', 'total_revenue' => '100000.00'],
            ['top_revenue' => '80000.00'],
        );

        $result = json_decode($this->sut->execute(['aggregation' => 'pareto_analysis', 'top_percentage' => 10]), true);

        self::assertSame('pareto_analysis', $result['aggregation']);
        self::assertSame(10, $result['top_percentage']);
        self::assertSame(100, $result['total_customers']);
        self::assertSame(10, $result['top_n_customers']);
        self::assertSame(100000.0, $result['total_revenue']);
        self::assertSame(80.0, $result['top_customers_revenue_pct']);
        self::assertSame(20.0, $result['remaining_customers_revenue_pct']);
    }

    public function test_execute_pareto_analysis_handles_no_customers(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn(['total_customers' => '0', 'total_revenue' => '0.00']);

        $result = json_decode($this->sut->execute(['aggregation' => 'pareto_analysis']), true);

        self::assertSame('pareto_analysis', $result['aggregation']);
        self::assertArrayHasKey('message', $result);
    }

    public function test_execute_top_customer_dependency_returns_revenue_share(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['total_revenue' => '50000.00'],
            ['cnt' => '200'],
        );

        $connection->method('fetchAll')->willReturn([
            [
                'customer_email' => 'vip@example.com',
                'customer_firstname' => 'VIP',
                'customer_lastname' => 'Customer',
                'order_count' => '25',
                'lifetime_revenue' => '5000.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'top_customer_dependency', 'top_percentage' => 10]), true);

        self::assertSame('top_customer_dependency', $result['aggregation']);
        self::assertSame(200, $result['total_customers']);
        self::assertSame(50000.0, $result['total_revenue']);
        self::assertCount(1, $result['rows']);
        self::assertSame('vip@example.com', $result['rows'][0]['customer_email']);
        self::assertSame(10.0, $result['rows'][0]['revenue_share_pct']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchRow')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute(['aggregation' => 'pareto_analysis']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
