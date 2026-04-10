<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\TimePatternAnalysisTool;

final class TimePatternAnalysisToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private TimePatternAnalysisTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new TimePatternAnalysisTool($this->resource);
    }

    public function test_get_name_returns_time_pattern_analysis(): void
    {
        self::assertSame('time_pattern_analysis', $this->sut->getName());
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
        self::assertContains('by_hour', $aggregationProp['enum']);
        self::assertContains('by_day_of_week', $aggregationProp['enum']);
        self::assertContains('by_month', $aggregationProp['enum']);
        self::assertContains('peak_hours', $aggregationProp['enum']);
        self::assertContains('seasonal_products', $aggregationProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'by_minute']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_by_hour_returns_24_hour_labels(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            ['hour' => '9', 'order_count' => '120', 'revenue' => '15000.00', 'avg_order_value' => '125.00'],
            ['hour' => '14', 'order_count' => '200', 'revenue' => '25000.00', 'avg_order_value' => '125.00'],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'by_hour']), true);

        self::assertSame('by_hour', $result['aggregation']);
        self::assertCount(2, $result['rows']);
        self::assertSame(9, $result['rows'][0]['hour']);
        self::assertSame('09:00', $result['rows'][0]['hour_label']);
        self::assertSame(120, $result['rows'][0]['order_count']);
    }

    public function test_execute_by_day_of_week_returns_day_names(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            ['day_number' => '2', 'day_name' => 'Monday', 'order_count' => '300', 'revenue' => '45000.00', 'avg_order_value' => '150.00'],
            ['day_number' => '7', 'day_name' => 'Saturday', 'order_count' => '450', 'revenue' => '67500.00', 'avg_order_value' => '150.00'],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'by_day_of_week']), true);

        self::assertSame('by_day_of_week', $result['aggregation']);
        self::assertSame('Monday', $result['rows'][0]['day_name']);
        self::assertSame(2, $result['rows'][0]['day_number']);
    }

    public function test_execute_peak_hours_returns_top_5(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            ['hour' => '14', 'order_count' => '200', 'revenue' => '25000.00'],
            ['hour' => '10', 'order_count' => '180', 'revenue' => '22000.00'],
            ['hour' => '15', 'order_count' => '170', 'revenue' => '21000.00'],
            ['hour' => '11', 'order_count' => '160', 'revenue' => '20000.00'],
            ['hour' => '19', 'order_count' => '150', 'revenue' => '18000.00'],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'peak_hours']), true);

        self::assertSame('peak_hours', $result['aggregation']);
        self::assertCount(5, $result['rows']);
        self::assertSame('14:00 - 15:00', $result['rows'][0]['hour_label']);
    }

    public function test_execute_seasonal_products_returns_variance_coefficient(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'sku' => 'SEASONAL-001',
                'product_name' => 'Christmas Sweater',
                'avg_monthly_units' => '10.00',
                'std_monthly_units' => '15.00',
                'variance_coefficient_pct' => '150.00',
                'active_months' => '6',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'seasonal_products']), true);

        self::assertSame('seasonal_products', $result['aggregation']);
        self::assertSame(150.0, $result['rows'][0]['variance_coefficient_pct']);
        self::assertArrayHasKey('description', $result);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('Timeout'));

        $result = json_decode($this->sut->execute(['aggregation' => 'by_hour']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
