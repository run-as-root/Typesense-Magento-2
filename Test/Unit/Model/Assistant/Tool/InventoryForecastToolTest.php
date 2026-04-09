<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\InventoryForecastTool;

final class InventoryForecastToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private InventoryForecastTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new InventoryForecastTool($this->resource);
    }

    public function test_get_name_returns_inventory_forecast(): void
    {
        self::assertSame('inventory_forecast', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_no_required_fields(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertEmpty($schema['required']);
    }

    public function test_get_parameters_schema_has_expected_properties(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('days_lookback', $schema['properties']);
        self::assertArrayHasKey('alert_threshold', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
    }

    public function test_execute_classifies_critical_status(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // 5 units, 2/day = 2.5 days => critical (<7)
        $connection->method('fetchAll')->willReturn([
            ['sku' => 'SKU-001', 'product_id' => 1, 'current_stock' => 5, 'avg_daily_sales' => 2.0],
        ]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertSame('critical', $result['products'][0]['status']);
        self::assertSame(3, $result['products'][0]['days_until_stockout']);
    }

    public function test_execute_classifies_warning_status(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // 20 units, 2/day = 10 days => warning (<14 default threshold)
        $connection->method('fetchAll')->willReturn([
            ['sku' => 'SKU-002', 'product_id' => 2, 'current_stock' => 20, 'avg_daily_sales' => 2.0],
        ]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertSame('warning', $result['products'][0]['status']);
        self::assertSame(10, $result['products'][0]['days_until_stockout']);
    }

    public function test_execute_classifies_ok_status(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // 100 units, 1/day = 100 days => ok (>=14 default threshold)
        $connection->method('fetchAll')->willReturn([
            ['sku' => 'SKU-003', 'product_id' => 3, 'current_stock' => 100, 'avg_daily_sales' => 1.0],
        ]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertSame('ok', $result['products'][0]['status']);
        self::assertSame(100, $result['products'][0]['days_until_stockout']);
    }

    public function test_execute_includes_forecast_metadata(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute([
            'days_lookback' => 30,
            'alert_threshold' => 14,
        ]), true);

        self::assertSame(30, $result['days_lookback']);
        self::assertSame(14, $result['alert_threshold_days']);
        self::assertSame(0, $result['count']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \Exception('DB error'));

        $result = json_decode($this->sut->execute([]), true);

        self::assertArrayHasKey('error', $result);
    }
}
