<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\DiscountEffectivenessTool;

final class DiscountEffectivenessToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private DiscountEffectivenessTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new DiscountEffectivenessTool($this->resource);
    }

    public function test_get_name_returns_discount_effectiveness(): void
    {
        self::assertSame('discount_effectiveness', $this->sut->getName());
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
        self::assertContains('aggregation', $schema['required']);
    }

    public function test_get_parameters_schema_aggregation_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $aggregationProp = $schema['properties']['aggregation'];

        self::assertArrayHasKey('enum', $aggregationProp);
        self::assertContains('coupon_performance', $aggregationProp['enum']);
        self::assertContains('discount_impact', $aggregationProp['enum']);
        self::assertContains('top_coupons', $aggregationProp['enum']);
        self::assertContains('coupon_vs_no_coupon', $aggregationProp['enum']);
    }

    public function test_get_parameters_schema_has_limit_property(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertSame('integer', $schema['properties']['limit']['type']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'invalid']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_coupon_performance_returns_rows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'coupon_code' => 'SAVE10',
                'uses' => '50',
                'total_discount' => '500.00',
                'total_revenue' => '4500.00',
                'avg_order_value' => '90.00',
                'avg_discount' => '10.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'coupon_performance']), true);

        self::assertSame('coupon_performance', $result['aggregation']);
        self::assertCount(1, $result['rows']);
        self::assertSame('SAVE10', $result['rows'][0]['coupon_code']);
        self::assertSame(50, $result['rows'][0]['uses']);
        self::assertSame(500.0, $result['rows'][0]['total_discount']);
    }

    public function test_execute_discount_impact_returns_summary(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'coupon_orders' => '100',
            'no_coupon_orders' => '400',
            'avg_order_value_with_coupon' => '95.00',
            'avg_order_value_without_coupon' => '75.00',
            'avg_discount_amount' => '12.50',
            'total_discounts_given' => '1250.00',
            'total_revenue' => '40000.00',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'discount_impact']), true);

        self::assertSame('discount_impact', $result['aggregation']);
        self::assertSame(100, $result['coupon_orders']);
        self::assertSame(400, $result['no_coupon_orders']);
        self::assertSame(95.0, $result['avg_order_value_with_coupon']);
        self::assertSame(75.0, $result['avg_order_value_without_coupon']);
    }

    public function test_execute_coupon_vs_no_coupon_calculates_repeat_rates(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'coupon_repeat_customers' => '40',
            'total_coupon_customers' => '100',
            'no_coupon_repeat_customers' => '30',
            'total_no_coupon_customers' => '200',
            'avg_ltv_coupon' => '320.00',
            'avg_ltv_no_coupon' => '180.00',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'coupon_vs_no_coupon']), true);

        self::assertSame('coupon_vs_no_coupon', $result['aggregation']);
        self::assertSame(40.0, $result['coupon_customers']['repeat_rate_pct']);
        self::assertSame(15.0, $result['no_coupon_customers']['repeat_rate_pct']);
        self::assertSame(320.0, $result['coupon_customers']['avg_ltv']);
    }

    public function test_execute_coupon_vs_no_coupon_handles_zero_customers(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'coupon_repeat_customers' => '0',
            'total_coupon_customers' => '0',
            'no_coupon_repeat_customers' => '0',
            'total_no_coupon_customers' => '0',
            'avg_ltv_coupon' => '0',
            'avg_ltv_no_coupon' => '0',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'coupon_vs_no_coupon']), true);

        self::assertNull($result['coupon_customers']['repeat_rate_pct']);
        self::assertNull($result['no_coupon_customers']['repeat_rate_pct']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute(['aggregation' => 'coupon_performance']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
