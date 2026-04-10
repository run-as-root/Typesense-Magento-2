<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\FunnelAnalysisTool;

final class FunnelAnalysisToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private FunnelAnalysisTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new FunnelAnalysisTool($this->resource);
    }

    public function test_get_name_returns_funnel_analysis(): void
    {
        self::assertSame('funnel_analysis', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_no_required_fields(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertEmpty($schema['required']);
    }

    public function test_get_parameters_schema_has_date_properties(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('date_from', $schema['properties']);
        self::assertArrayHasKey('date_to', $schema['properties']);
        self::assertSame('string', $schema['properties']['date_from']['type']);
        self::assertSame('string', $schema['properties']['date_to']['type']);
    }

    public function test_execute_returns_error_for_invalid_date_from(): void
    {
        $result = json_decode($this->sut->execute(['date_from' => '01/01/2025']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('date_from', $result['error']);
    }

    public function test_execute_returns_error_for_invalid_date_to(): void
    {
        $result = json_decode($this->sut->execute(['date_to' => 'not-a-date']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('date_to', $result['error']);
    }

    public function test_execute_returns_funnel_data(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'total_quotes' => '500',
            'quotes_with_items' => '300',
            'avg_abandoned_cart_value' => '85.50',
        ]);

        $connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('120', '2.5');

        $connection->method('fetchAll')->willReturn([
            ['name' => 'Widget A', 'sku' => 'WGT-A', 'abandoned_count' => '15'],
        ]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertSame(500, $result['total_quotes']);
        self::assertSame(300, $result['quotes_with_items']);
        self::assertSame(120, $result['orders_placed']);
        self::assertArrayHasKey('abandonment_rate_pct', $result);
        self::assertArrayHasKey('avg_abandoned_cart_value', $result);
        self::assertArrayHasKey('most_abandoned_products', $result);
        self::assertCount(1, $result['most_abandoned_products']);
        self::assertSame('WGT-A', $result['most_abandoned_products'][0]['sku']);
    }

    public function test_execute_calculates_abandonment_rate_correctly(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // 200 quotes with items, 100 orders => 50% abandonment
        $connection->method('fetchRow')->willReturn([
            'total_quotes' => '250',
            'quotes_with_items' => '200',
            'avg_abandoned_cart_value' => '60.00',
        ]);

        $connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('100', '1.0');

        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertSame(50.0, $result['abandonment_rate_pct']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willThrowException(new \RuntimeException('DB error'));

        $result = json_decode($this->sut->execute([]), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }

    public function test_execute_includes_date_filters_in_result(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'total_quotes' => '0',
            'quotes_with_items' => '0',
            'avg_abandoned_cart_value' => '0',
        ]);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute([
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-31',
        ]), true);

        self::assertSame('2025-01-01', $result['date_from']);
        self::assertSame('2025-01-31', $result['date_to']);
    }
}
