<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ComparePeriodsTool;

final class ComparePeriodsToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private ComparePeriodsTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new ComparePeriodsTool($this->resource);
    }

    public function test_get_name_returns_compare_periods(): void
    {
        self::assertSame('compare_periods', $this->sut->getName());
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

        $required = $schema['required'];
        self::assertContains('metric', $required);
        self::assertContains('period_1_start', $required);
        self::assertContains('period_1_end', $required);
        self::assertContains('period_2_start', $required);
        self::assertContains('period_2_end', $required);
    }

    public function test_get_parameters_schema_metric_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $metricProp = $schema['properties']['metric'];

        self::assertArrayHasKey('enum', $metricProp);
        self::assertContains('revenue', $metricProp['enum']);
        self::assertContains('order_count', $metricProp['enum']);
        self::assertContains('new_customers', $metricProp['enum']);
        self::assertContains('avg_order_value', $metricProp['enum']);
        self::assertContains('units_sold', $metricProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_metric(): void
    {
        $result = json_decode($this->sut->execute([
            'metric' => 'invalid_metric',
            'period_1_start' => '2025-01-01',
            'period_1_end' => '2025-01-31',
            'period_2_start' => '2025-02-01',
            'period_2_end' => '2025-02-28',
        ]), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid metric', $result['error']);
    }

    public function test_execute_returns_error_for_invalid_date_format(): void
    {
        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'period_1_start' => '01/01/2025',
            'period_1_end' => '2025-01-31',
            'period_2_start' => '2025-02-01',
            'period_2_end' => '2025-02-28',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_comparison_with_direction_up(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // Period 1: 100, Period 2: 150 => direction up
        $connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('100.00', '150.00');

        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'period_1_start' => '2025-01-01',
            'period_1_end' => '2025-01-31',
            'period_2_start' => '2025-02-01',
            'period_2_end' => '2025-02-28',
        ]), true);

        self::assertArrayHasKey('direction', $result);
        self::assertSame('up', $result['direction']);
        self::assertSame(50.0, $result['absolute_change']);
        self::assertSame(50.0, $result['percentage_change']);
    }

    public function test_execute_returns_direction_down_when_value_decreases(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('200.00', '100.00');

        $result = json_decode($this->sut->execute([
            'metric' => 'order_count',
            'period_1_start' => '2025-01-01',
            'period_1_end' => '2025-01-31',
            'period_2_start' => '2025-02-01',
            'period_2_end' => '2025-02-28',
        ]), true);

        self::assertSame('down', $result['direction']);
        self::assertSame(-100.0, $result['absolute_change']);
        self::assertSame(-50.0, $result['percentage_change']);
    }

    public function test_execute_returns_direction_flat_when_equal(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('100.00', '100.00');

        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'period_1_start' => '2025-01-01',
            'period_1_end' => '2025-01-31',
            'period_2_start' => '2025-02-01',
            'period_2_end' => '2025-02-28',
        ]), true);

        self::assertSame('flat', $result['direction']);
        self::assertSame(0.0, $result['absolute_change']);
    }

    public function test_execute_handles_zero_period_1_value(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('0', '150.00');

        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'period_1_start' => '2025-01-01',
            'period_1_end' => '2025-01-31',
            'period_2_start' => '2025-02-01',
            'period_2_end' => '2025-02-28',
        ]), true);

        self::assertSame('up', $result['direction']);
        self::assertNull($result['percentage_change']);
    }
}
