<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ShippingPerformanceTool;

final class ShippingPerformanceToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private ShippingPerformanceTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new ShippingPerformanceTool($this->resource);
    }

    public function test_get_name_returns_shipping_performance(): void
    {
        self::assertSame('shipping_performance', $this->sut->getName());
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
        self::assertContains('fulfillment_time', $aggregationProp['enum']);
        self::assertContains('shipping_method_usage', $aggregationProp['enum']);
        self::assertContains('shipping_cost_analysis', $aggregationProp['enum']);
        self::assertContains('free_shipping_rate', $aggregationProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'teleport_rate']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_fulfillment_time_returns_day_metrics(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'shipment_count' => '350',
            'avg_fulfillment_days' => '1.80',
            'min_fulfillment_days' => '0.00',
            'max_fulfillment_days' => '14.00',
            'same_day_count' => '50',
            'next_day_count' => '200',
            'over_one_day_count' => '100',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'fulfillment_time']), true);

        self::assertSame('fulfillment_time', $result['aggregation']);
        self::assertSame(350, $result['shipment_count']);
        self::assertSame(1.8, $result['avg_fulfillment_days']);
        self::assertSame(50, $result['same_day_count']);
        self::assertSame(200, $result['next_day_count']);
    }

    public function test_execute_shipping_method_usage_returns_methods_with_percentage(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // First call is fetchRow for total count, second is fetchAll for methods
        $connection->method('fetchRow')->willReturn(['total' => '1000']);
        $connection->method('fetchAll')->willReturn([
            [
                'shipping_description' => 'Free Shipping - Free',
                'order_count' => '600',
                'total_shipping_revenue' => '0.00',
                'avg_shipping_cost' => '0.00',
            ],
            [
                'shipping_description' => 'UPS - Ground',
                'order_count' => '400',
                'total_shipping_revenue' => '3200.00',
                'avg_shipping_cost' => '8.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'shipping_method_usage']), true);

        self::assertSame('shipping_method_usage', $result['aggregation']);
        self::assertCount(2, $result['rows']);
        self::assertSame('Free Shipping - Free', $result['rows'][0]['shipping_method']);
        self::assertSame(60.0, $result['rows'][0]['usage_pct']);
    }

    public function test_execute_free_shipping_rate_returns_rate_percentage(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'total_orders' => '1000',
            'free_shipping_orders' => '600',
            'paid_shipping_orders' => '400',
            'total_shipping_collected' => '3200.00',
            'avg_paid_shipping_cost' => '8.00',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'free_shipping_rate']), true);

        self::assertSame('free_shipping_rate', $result['aggregation']);
        self::assertSame(1000, $result['total_orders']);
        self::assertSame(600, $result['free_shipping_orders']);
        self::assertSame(60.0, $result['free_shipping_rate_pct']);
        self::assertSame(8.0, $result['avg_paid_shipping_cost']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchRow')->willThrowException(new \RuntimeException('DB fail'));

        $result = json_decode($this->sut->execute(['aggregation' => 'fulfillment_time']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
