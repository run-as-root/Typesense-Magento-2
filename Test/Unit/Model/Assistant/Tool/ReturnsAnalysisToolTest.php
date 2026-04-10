<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ReturnsAnalysisTool;

final class ReturnsAnalysisToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private ReturnsAnalysisTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new ReturnsAnalysisTool($this->resource);
    }

    public function test_get_name_returns_returns_analysis(): void
    {
        self::assertSame('returns_analysis', $this->sut->getName());
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
        self::assertContains('return_rate_by_product', $aggregationProp['enum']);
        self::assertContains('return_rate_by_category', $aggregationProp['enum']);
        self::assertContains('total_refunds', $aggregationProp['enum']);
        self::assertContains('refund_trend', $aggregationProp['enum']);
    }

    public function test_get_parameters_schema_has_limit_property(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertSame('integer', $schema['properties']['limit']['type']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'bad']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_return_rate_by_product_returns_rows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'sku' => 'PROD-001',
                'name' => 'Product One',
                'qty_refunded' => '3.00',
                'qty_sold' => '30.00',
                'total_refunded' => '150.00',
                'return_rate_pct' => '10.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'return_rate_by_product']), true);

        self::assertSame('return_rate_by_product', $result['aggregation']);
        self::assertCount(1, $result['rows']);
        self::assertSame('PROD-001', $result['rows'][0]['sku']);
        self::assertSame(10.0, $result['rows'][0]['return_rate_pct']);
        self::assertSame(150.0, $result['rows'][0]['total_refunded']);
    }

    public function test_execute_return_rate_by_product_handles_null_return_rate(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'sku' => 'PROD-002',
                'name' => 'Product Two',
                'qty_refunded' => '2.00',
                'qty_sold' => '0.00',
                'total_refunded' => '80.00',
                'return_rate_pct' => null,
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'return_rate_by_product']), true);

        self::assertNull($result['rows'][0]['return_rate_pct']);
    }

    public function test_execute_total_refunds_returns_summary(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'total_creditmemos' => '25',
            'total_refunded' => '2500.00',
            'total_adjustment_positive' => '100.00',
            'total_adjustment_negative' => '50.00',
            'avg_refund_amount' => '100.00',
            'earliest_refund' => '2024-02-01 08:00:00',
            'latest_refund' => '2025-03-15 14:30:00',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'total_refunds']), true);

        self::assertSame('total_refunds', $result['aggregation']);
        self::assertSame(25, $result['total_creditmemos']);
        self::assertSame(2500.0, $result['total_refunded']);
        self::assertSame(100.0, $result['avg_refund_amount']);
        self::assertSame('2024-02-01 08:00:00', $result['earliest_refund']);
    }

    public function test_execute_refund_trend_returns_monthly_rows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            ['month' => '2025-03', 'refund_count' => '8', 'total_refunded' => '640.00', 'avg_refund_amount' => '80.00'],
            ['month' => '2025-02', 'refund_count' => '5', 'total_refunded' => '375.00', 'avg_refund_amount' => '75.00'],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'refund_trend']), true);

        self::assertSame('refund_trend', $result['aggregation']);
        self::assertCount(2, $result['rows']);
        self::assertSame('2025-03', $result['rows'][0]['month']);
        self::assertSame(8, $result['rows'][0]['refund_count']);
        self::assertSame(640.0, $result['rows'][0]['total_refunded']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute(['aggregation' => 'return_rate_by_product']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
