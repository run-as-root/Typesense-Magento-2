<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\DetectAnomaliesTool;

final class DetectAnomaliesToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private DetectAnomaliesTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new DetectAnomaliesTool($this->resource);
    }

    public function test_get_name_returns_detect_anomalies(): void
    {
        self::assertSame('detect_anomalies', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_required_fields(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertContains('metric', $schema['required']);
        self::assertContains('compare_window', $schema['required']);
    }

    public function test_get_parameters_schema_metric_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $metricProp = $schema['properties']['metric'];

        self::assertArrayHasKey('enum', $metricProp);
        self::assertContains('revenue', $metricProp['enum']);
        self::assertContains('order_count', $metricProp['enum']);
        self::assertContains('avg_order_value', $metricProp['enum']);
        self::assertContains('new_customers', $metricProp['enum']);
    }

    public function test_get_parameters_schema_compare_window_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $windowProp = $schema['properties']['compare_window'];

        self::assertArrayHasKey('enum', $windowProp);
        self::assertContains('today_vs_avg', $windowProp['enum']);
        self::assertContains('this_week_vs_avg', $windowProp['enum']);
        self::assertContains('this_month_vs_avg', $windowProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_metric(): void
    {
        $result = json_decode($this->sut->execute([
            'metric' => 'invalid',
            'compare_window' => 'today_vs_avg',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_error_for_invalid_compare_window(): void
    {
        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'compare_window' => 'last_year',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_calculate_mean_with_values(): void
    {
        $mean = $this->sut->calculateMean([10.0, 20.0, 30.0]);
        self::assertEqualsWithDelta(20.0, $mean, 0.001);
    }

    public function test_calculate_mean_with_empty_array(): void
    {
        $mean = $this->sut->calculateMean([]);
        self::assertSame(0.0, $mean);
    }

    public function test_calculate_stddev_with_known_values(): void
    {
        // Population stddev of [10, 20, 30] with mean=20 is sqrt(200/3) ≈ 8.165
        $values = [10.0, 20.0, 30.0];
        $mean = 20.0;
        $stddev = $this->sut->calculateStddev($values, $mean);
        self::assertEqualsWithDelta(8.165, $stddev, 0.001);
    }

    public function test_calculate_stddev_returns_zero_for_single_value(): void
    {
        $stddev = $this->sut->calculateStddev([100.0], 100.0);
        self::assertSame(0.0, $stddev);
    }

    public function test_calculate_z_score_normal(): void
    {
        // Value exactly at mean = z-score of 0
        $zScore = $this->sut->calculateZScore(20.0, 20.0, 5.0);
        self::assertEqualsWithDelta(0.0, $zScore, 0.001);
    }

    public function test_calculate_z_score_positive(): void
    {
        // One stddev above mean
        $zScore = $this->sut->calculateZScore(25.0, 20.0, 5.0);
        self::assertEqualsWithDelta(1.0, $zScore, 0.001);
    }

    public function test_calculate_z_score_negative(): void
    {
        // Two stddevs below mean
        $zScore = $this->sut->calculateZScore(10.0, 20.0, 5.0);
        self::assertEqualsWithDelta(-2.0, $zScore, 0.001);
    }

    public function test_calculate_z_score_with_zero_stddev(): void
    {
        // When stddev is 0, z-score should be 0 (no deviation)
        $zScore = $this->sut->calculateZScore(100.0, 100.0, 0.0);
        self::assertSame(0.0, $zScore);
    }

    public function test_execute_returns_normal_status_for_low_z_score(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // Current value: 100, historical: [95, 100, 105, 100] => mean=100, small stddev => z ~ 0
        $connection->method('fetchOne')->willReturn('100');
        $connection->method('fetchAll')->willReturn([
            ['value' => 95.0],
            ['value' => 100.0],
            ['value' => 105.0],
            ['value' => 100.0],
        ]);

        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'compare_window' => 'today_vs_avg',
        ]), true);

        self::assertArrayHasKey('status', $result);
        self::assertSame('normal', $result['status']);
        self::assertSame(100.0, $result['current_value']);
    }

    public function test_execute_returns_critical_status_for_high_z_score(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // Current value: 500, historical: [100, 100, 100, 100] => mean=100, stddev=0... use varied
        // historical: [90, 100, 110, 100] => mean=100, stddev~7.07 => z=(500-100)/7.07 ≈ 56.5 => critical
        $connection->method('fetchOne')->willReturn('500');
        $connection->method('fetchAll')->willReturn([
            ['value' => 90.0],
            ['value' => 100.0],
            ['value' => 110.0],
            ['value' => 100.0],
        ]);

        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'compare_window' => 'this_week_vs_avg',
        ]), true);

        self::assertSame('critical', $result['status']);
    }

    public function test_execute_handles_insufficient_historical_data(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchOne')->willReturn('100');
        $connection->method('fetchAll')->willReturn([
            ['value' => 95.0],
        ]);

        $result = json_decode($this->sut->execute([
            'metric' => 'order_count',
            'compare_window' => 'this_month_vs_avg',
        ]), true);

        self::assertArrayHasKey('message', $result);
        self::assertStringContainsString('Insufficient', $result['message']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchOne')->willThrowException(new \Exception('DB error'));

        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'compare_window' => 'today_vs_avg',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }
}
