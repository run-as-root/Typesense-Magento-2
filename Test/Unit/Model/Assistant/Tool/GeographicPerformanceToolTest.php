<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\GeographicPerformanceTool;

final class GeographicPerformanceToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private GeographicPerformanceTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new GeographicPerformanceTool($this->resource);
    }

    public function test_get_name_returns_geographic_performance(): void
    {
        self::assertSame('geographic_performance', $this->sut->getName());
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

    public function test_get_parameters_schema_has_optional_country_and_limit(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('country', $schema['properties']);
        self::assertSame('string', $schema['properties']['country']['type']);
        self::assertArrayHasKey('limit', $schema['properties']);
    }

    public function test_get_parameters_schema_aggregation_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $aggregationProp = $schema['properties']['aggregation'];

        self::assertArrayHasKey('enum', $aggregationProp);
        self::assertContains('revenue_by_country', $aggregationProp['enum']);
        self::assertContains('revenue_by_region', $aggregationProp['enum']);
        self::assertContains('top_cities', $aggregationProp['enum']);
        self::assertContains('aov_by_country', $aggregationProp['enum']);
        self::assertContains('product_preferences_by_country', $aggregationProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'by_planet']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_revenue_by_country_returns_country_rows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'country_id' => 'US',
                'order_count' => '500',
                'revenue' => '75000.00',
                'avg_order_value' => '150.00',
            ],
            [
                'country_id' => 'DE',
                'order_count' => '200',
                'revenue' => '28000.00',
                'avg_order_value' => '140.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'revenue_by_country']), true);

        self::assertSame('revenue_by_country', $result['aggregation']);
        self::assertNull($result['country_filter']);
        self::assertCount(2, $result['rows']);
        self::assertSame('US', $result['rows'][0]['country_id']);
        self::assertSame(75000.0, $result['rows'][0]['revenue']);
    }

    public function test_execute_with_country_filter_passes_it_through(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute(['aggregation' => 'top_cities', 'country' => 'us']), true);

        // Country should be uppercased
        self::assertSame('US', $result['country_filter']);
    }

    public function test_execute_aov_by_country_returns_order_value_stats(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'country_id' => 'CH',
                'order_count' => '50',
                'avg_order_value' => '280.00',
                'min_order_value' => '50.00',
                'max_order_value' => '1200.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'aov_by_country']), true);

        self::assertSame('aov_by_country', $result['aggregation']);
        self::assertSame(280.0, $result['rows'][0]['avg_order_value']);
        self::assertSame(1200.0, $result['rows'][0]['max_order_value']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('Query failed'));

        $result = json_decode($this->sut->execute(['aggregation' => 'revenue_by_country']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
