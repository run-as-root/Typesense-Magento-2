<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ProductVelocityTool;

final class ProductVelocityToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private ProductVelocityTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new ProductVelocityTool($this->resource);
    }

    public function test_get_name_returns_product_velocity(): void
    {
        self::assertSame('product_velocity', $this->sut->getName());
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
        self::assertContains('aggregation', $schema['required']);
    }

    public function test_get_parameters_schema_aggregation_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $aggregationProp = $schema['properties']['aggregation'];

        self::assertArrayHasKey('enum', $aggregationProp);
        self::assertContains('fast_movers', $aggregationProp['enum']);
        self::assertContains('slow_movers', $aggregationProp['enum']);
        self::assertContains('dead_stock', $aggregationProp['enum']);
        self::assertContains('sell_through_rate', $aggregationProp['enum']);
    }

    public function test_get_parameters_schema_has_days_and_limit_properties(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('days', $schema['properties']);
        self::assertSame('integer', $schema['properties']['days']['type']);
        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertSame('integer', $schema['properties']['limit']['type']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'invalid']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_fast_movers_returns_rows_with_velocity(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'sku' => 'FAST-001',
                'name' => 'Fast Product',
                'units_sold' => '150',
                'units_per_day' => '5.0000',
                'revenue' => '1500.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'fast_movers', 'days' => 30]), true);

        self::assertSame('fast_movers', $result['aggregation']);
        self::assertSame(30, $result['days']);
        self::assertCount(1, $result['rows']);
        self::assertSame('FAST-001', $result['rows'][0]['sku']);
        self::assertSame(5.0, $result['rows'][0]['units_per_day']);
    }

    public function test_execute_dead_stock_returns_products_without_sales(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            ['sku' => 'DEAD-001', 'current_stock' => '50.00'],
            ['sku' => 'DEAD-002', 'current_stock' => '12.00'],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'dead_stock', 'days' => 90]), true);

        self::assertSame('dead_stock', $result['aggregation']);
        self::assertSame(90, $result['days']);
        self::assertCount(2, $result['rows']);
        self::assertSame('DEAD-001', $result['rows'][0]['sku']);
        self::assertSame(50.0, $result['rows'][0]['current_stock']);
    }

    public function test_execute_sell_through_rate_returns_percentage(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'sku' => 'STR-001',
                'name' => 'Product A',
                'units_sold' => '80',
                'current_stock' => '20.00',
                'sell_through_rate_pct' => '80.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'sell_through_rate']), true);

        self::assertSame('sell_through_rate', $result['aggregation']);
        self::assertSame(80.0, $result['rows'][0]['sell_through_rate_pct']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute(['aggregation' => 'fast_movers']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
