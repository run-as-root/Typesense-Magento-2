<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\RevenueAttributionTool;

final class RevenueAttributionToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private RevenueAttributionTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new RevenueAttributionTool($this->resource);
    }

    public function test_get_name_returns_revenue_attribution(): void
    {
        self::assertSame('revenue_attribution', $this->sut->getName());
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
        self::assertContains('by_coupon_code', $aggregationProp['enum']);
        self::assertContains('by_source', $aggregationProp['enum']);
        self::assertContains('summary', $aggregationProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'bad']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_by_coupon_code_returns_revenue_per_code(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'coupon_code' => 'SUMMER20',
                'order_count' => '15',
                'revenue' => '2250.00',
                'avg_order_value' => '150.00',
                'total_discount' => '-450.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'by_coupon_code']), true);

        self::assertSame('by_coupon_code', $result['aggregation']);
        self::assertCount(1, $result['rows']);
        self::assertSame('SUMMER20', $result['rows'][0]['coupon_code']);
        self::assertSame(15, $result['rows'][0]['order_count']);
        self::assertSame(2250.0, $result['rows'][0]['revenue']);
    }

    public function test_execute_summary_returns_coupon_vs_non_coupon_split(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'coupon_orders' => '40',
            'non_coupon_orders' => '60',
            'total_orders' => '100',
            'coupon_revenue' => '4000.00',
            'non_coupon_revenue' => '6000.00',
            'total_revenue' => '10000.00',
            'total_discount_given' => '-800.00',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'summary']), true);

        self::assertSame('summary', $result['aggregation']);
        self::assertSame(100, $result['total_orders']);
        self::assertSame(40, $result['coupon_orders']);
        self::assertSame(10000.0, $result['total_revenue']);
        self::assertSame(40.0, $result['coupon_revenue_pct']);
    }

    public function test_execute_by_source_returns_note_about_fallback(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute(['aggregation' => 'by_source']), true);

        self::assertSame('by_source', $result['aggregation']);
        self::assertArrayHasKey('note', $result);
        self::assertStringContainsString('coupon', strtolower($result['note']));
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute(['aggregation' => 'by_coupon_code']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
