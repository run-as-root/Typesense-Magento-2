<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\BasketAnalysisTool;

final class BasketAnalysisToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private BasketAnalysisTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new BasketAnalysisTool($this->resource);
    }

    public function test_get_name_returns_basket_analysis(): void
    {
        self::assertSame('basket_analysis', $this->sut->getName());
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
        self::assertContains('abandoned_carts_summary', $aggregationProp['enum']);
        self::assertContains('abandoned_cart_products', $aggregationProp['enum']);
        self::assertContains('cart_value_distribution', $aggregationProp['enum']);
        self::assertContains('active_carts', $aggregationProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'unknown']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_abandoned_carts_summary_returns_metrics(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'abandoned_cart_count' => '42',
            'total_value' => '3150.00',
            'avg_value' => '75.00',
            'avg_age_hours' => '18.5',
            'total_items' => '126',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'abandoned_carts_summary']), true);

        self::assertSame('abandoned_carts_summary', $result['aggregation']);
        self::assertSame(42, $result['abandoned_cart_count']);
        self::assertSame(3150.0, $result['total_value']);
        self::assertSame(75.0, $result['avg_value']);
        self::assertSame(18.5, $result['avg_age_hours']);
    }

    public function test_execute_cart_value_distribution_returns_buckets(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchRow')->willReturn([
            'under_50' => '10',
            'between_50_100' => '20',
            'between_100_200' => '15',
            'over_200' => '5',
            'total_carts' => '50',
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'cart_value_distribution']), true);

        self::assertSame('cart_value_distribution', $result['aggregation']);
        self::assertSame(50, $result['total_carts']);
        self::assertCount(4, $result['buckets']);
        self::assertSame('Under $50', $result['buckets'][0]['range']);
        self::assertSame(10, $result['buckets'][0]['count']);
        self::assertSame(20.0, $result['buckets'][0]['pct']);
    }

    public function test_execute_abandoned_cart_products_returns_rows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'sku' => 'ABC-123',
                'name' => 'Popular Abandoned Item',
                'cart_count' => '15',
                'total_qty' => '20',
                'total_value' => '599.85',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'abandoned_cart_products']), true);

        self::assertSame('abandoned_cart_products', $result['aggregation']);
        self::assertCount(1, $result['rows']);
        self::assertSame('ABC-123', $result['rows'][0]['sku']);
        self::assertSame(15, $result['rows'][0]['cart_count']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchRow')->willThrowException(new \RuntimeException('DB fail'));

        $result = json_decode($this->sut->execute(['aggregation' => 'abandoned_carts_summary']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
