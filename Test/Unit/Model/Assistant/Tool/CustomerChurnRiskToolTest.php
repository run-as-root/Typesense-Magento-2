<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\CustomerChurnRiskTool;

final class CustomerChurnRiskToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private CustomerChurnRiskTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new CustomerChurnRiskTool($this->resource);
    }

    public function test_get_name_returns_customer_churn_risk(): void
    {
        self::assertSame('customer_churn_risk', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_correct_structure(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('risk_level', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
    }

    public function test_get_parameters_schema_risk_level_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $riskProp = $schema['properties']['risk_level'];

        self::assertArrayHasKey('enum', $riskProp);
        self::assertContains('high', $riskProp['enum']);
        self::assertContains('medium', $riskProp['enum']);
        self::assertContains('low', $riskProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_risk_level(): void
    {
        $result = json_decode($this->sut->execute(['risk_level' => 'extreme']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid risk_level', $result['error']);
    }

    public function test_execute_without_filter_returns_all_risk_levels(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'customer_email' => 'high@example.com',
                'customer_firstname' => 'John',
                'customer_lastname' => 'Doe',
                'order_count' => '5',
                'avg_interval_days' => '30.0',
                'days_since_last_order' => '90.0',
                'risk_level' => 'high',
            ],
            [
                'customer_email' => 'low@example.com',
                'customer_firstname' => 'Jane',
                'customer_lastname' => 'Smith',
                'order_count' => '3',
                'avg_interval_days' => '60.0',
                'days_since_last_order' => '45.0',
                'risk_level' => 'low',
            ],
        ]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertNull($result['risk_level_filter']);
        self::assertCount(2, $result['rows']);
        self::assertSame('high@example.com', $result['rows'][0]['customer_email']);
        self::assertSame('John Doe', $result['rows'][0]['name']);
        self::assertSame('high', $result['rows'][0]['risk_level']);
        self::assertSame(5, $result['rows'][0]['order_count']);
    }

    public function test_execute_with_high_risk_filter_passes_filter(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute(['risk_level' => 'high']), true);

        self::assertSame('high', $result['risk_level_filter']);
        self::assertIsArray($result['rows']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('DB connection lost'));

        $result = json_decode($this->sut->execute([]), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
