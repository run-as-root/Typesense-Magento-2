<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ProfitAnalysisTool;

final class ProfitAnalysisToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private ProfitAnalysisTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new ProfitAnalysisTool($this->resource);
    }

    public function test_get_name_returns_profit_analysis(): void
    {
        self::assertSame('profit_analysis', $this->sut->getName());
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
        self::assertContains('profit_by_product', $aggregationProp['enum']);
        self::assertContains('profit_by_category', $aggregationProp['enum']);
        self::assertContains('profit_margin_trend', $aggregationProp['enum']);
        self::assertContains('overall_summary', $aggregationProp['enum']);
    }

    public function test_get_parameters_schema_has_limit_property(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertSame('integer', $schema['properties']['limit']['type']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'bad_aggregation']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_profit_by_product_returns_rows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'name' => 'Test Product',
                'sku' => 'TEST-001',
                'units_sold' => '5',
                'revenue' => '500.00',
                'total_cost' => '200.00',
                'gross_profit' => '300.00',
                'margin_pct' => '60.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'profit_by_product']), true);

        self::assertSame('profit_by_product', $result['aggregation']);
        self::assertCount(1, $result['rows']);
        self::assertSame('TEST-001', $result['rows'][0]['sku']);
        self::assertSame(300.0, $result['rows'][0]['gross_profit']);
        self::assertSame(60.0, $result['rows'][0]['margin_pct']);
    }

    public function test_execute_overall_summary_returns_single_row(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'total_revenue' => '10000.00',
            'total_cost' => '4000.00',
            'total_profit' => '6000.00',
            'avg_margin_pct' => '60.00',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'overall_summary']), true);

        self::assertSame('overall_summary', $result['aggregation']);
        self::assertSame(10000.0, $result['total_revenue']);
        self::assertSame(6000.0, $result['total_profit']);
        self::assertSame(60.0, $result['avg_margin_pct']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute(['aggregation' => 'profit_by_product']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
