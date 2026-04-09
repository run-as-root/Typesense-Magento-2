<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\TrendAnalysisTool;

final class TrendAnalysisToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private TrendAnalysisTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new TrendAnalysisTool($this->resource);
    }

    public function test_get_name_returns_trend_analysis(): void
    {
        self::assertSame('trend_analysis', $this->sut->getName());
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
        self::assertContains('metric', $schema['required']);
        self::assertContains('granularity', $schema['required']);
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
    }

    public function test_get_parameters_schema_granularity_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $granularityProp = $schema['properties']['granularity'];

        self::assertArrayHasKey('enum', $granularityProp);
        self::assertContains('day', $granularityProp['enum']);
        self::assertContains('week', $granularityProp['enum']);
        self::assertContains('month', $granularityProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_metric(): void
    {
        $result = json_decode($this->sut->execute([
            'metric' => 'bad_metric',
            'granularity' => 'month',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_error_for_invalid_granularity(): void
    {
        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'granularity' => 'quarter',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_error_for_out_of_range_periods(): void
    {
        $result = json_decode($this->sut->execute([
            'metric' => 'revenue',
            'granularity' => 'month',
            'periods' => 0,
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_calculate_moving_average_with_enough_data(): void
    {
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];
        $result = $this->sut->calculateMovingAverage($values, 3);

        self::assertNull($result[0]);
        self::assertNull($result[1]);
        self::assertEqualsWithDelta(20.0, $result[2], 0.001); // (10+20+30)/3
        self::assertEqualsWithDelta(30.0, $result[3], 0.001); // (20+30+40)/3
        self::assertEqualsWithDelta(40.0, $result[4], 0.001); // (30+40+50)/3
    }

    public function test_calculate_moving_average_returns_nulls_for_insufficient_data(): void
    {
        $values = [10.0, 20.0];
        $result = $this->sut->calculateMovingAverage($values, 3);

        self::assertNull($result[0]);
        self::assertNull($result[1]);
    }

    public function test_calculate_growth_rate_positive(): void
    {
        $rate = $this->sut->calculateGrowthRate([100.0, 150.0]);
        self::assertEqualsWithDelta(50.0, $rate, 0.001);
    }

    public function test_calculate_growth_rate_negative(): void
    {
        $rate = $this->sut->calculateGrowthRate([200.0, 100.0]);
        self::assertEqualsWithDelta(-50.0, $rate, 0.001);
    }

    public function test_calculate_growth_rate_returns_null_for_zero_first_value(): void
    {
        $rate = $this->sut->calculateGrowthRate([0.0, 100.0]);
        self::assertNull($rate);
    }

    public function test_calculate_growth_rate_returns_null_for_single_value(): void
    {
        $rate = $this->sut->calculateGrowthRate([100.0]);
        self::assertNull($rate);
    }

    public function test_determine_direction_growing(): void
    {
        self::assertSame('growing', $this->sut->determineDirection(10.0));
        self::assertSame('growing', $this->sut->determineDirection(5.1));
    }

    public function test_determine_direction_declining(): void
    {
        self::assertSame('declining', $this->sut->determineDirection(-10.0));
        self::assertSame('declining', $this->sut->determineDirection(-5.1));
    }

    public function test_determine_direction_stable(): void
    {
        self::assertSame('stable', $this->sut->determineDirection(0.0));
        self::assertSame('stable', $this->sut->determineDirection(4.9));
        self::assertSame('stable', $this->sut->determineDirection(-4.9));
        self::assertSame('stable', $this->sut->determineDirection(null));
    }
}
