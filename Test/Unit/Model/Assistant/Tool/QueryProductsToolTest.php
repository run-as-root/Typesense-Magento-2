<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\QueryProductsTool;

final class QueryProductsToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private AdapterInterface&MockObject $connection;
    private QueryProductsTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnCallback(
            static fn(string $table) => $table
        );

        $this->sut = new QueryProductsTool($this->resource);
    }

    public function test_get_name_returns_query_products(): void
    {
        self::assertSame('query_products', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_required_aggregation(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('aggregation', $schema['properties']);
        self::assertContains('aggregation', $schema['required']);
    }

    public function test_get_parameters_schema_aggregation_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $enum = $schema['properties']['aggregation']['enum'];

        self::assertContains('top_by_sales_count', $enum);
        self::assertContains('low_stock', $enum);
        self::assertContains('price_range', $enum);
        self::assertContains('count_by_category', $enum);
    }

    public function test_execute_top_by_sales_count_joins_order_item(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(self::stringContainsString('sales_order_item'))
            ->willReturn([
                ['sku' => 'WSH12-XS-Purple', 'name' => 'Jade Yoga Shoulder Bag', 'sales_count' => '87'],
            ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'top_by_sales_count']), true);

        self::assertSame('top_by_sales_count', $result['aggregation']);
        self::assertCount(1, $result['rows']);
    }

    public function test_execute_low_stock_uses_threshold_parameter(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::stringContainsString('cataloginventory_stock_item'),
                self::arrayHasKey(':threshold')
            )
            ->willReturn([]);

        $result = json_decode(
            $this->sut->execute(['aggregation' => 'low_stock', 'threshold' => 5]),
            true
        );

        self::assertSame('low_stock', $result['aggregation']);
        self::assertSame(5, $result['threshold']);
    }

    public function test_execute_price_range_queries_decimal_table(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(self::stringContainsString('catalog_product_entity_decimal'))
            ->willReturn([['min_price' => '9.99', 'max_price' => '999.00', 'avg_price' => '49.50']]);

        $result = json_decode($this->sut->execute(['aggregation' => 'price_range']), true);

        self::assertSame('price_range', $result['aggregation']);
    }

    public function test_execute_count_by_category_queries_category_product(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with(self::stringContainsString('catalog_category_product'))
            ->willReturn([['category_id' => '5', 'product_count' => '200']]);

        $result = json_decode($this->sut->execute(['aggregation' => 'count_by_category']), true);

        self::assertSame('count_by_category', $result['aggregation']);
    }

    public function test_execute_returns_error_for_unknown_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'unknown']), true);

        self::assertArrayHasKey('error', $result);
    }
}
