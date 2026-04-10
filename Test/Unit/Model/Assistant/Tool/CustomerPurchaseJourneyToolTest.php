<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\CustomerPurchaseJourneyTool;

final class CustomerPurchaseJourneyToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private CustomerPurchaseJourneyTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new CustomerPurchaseJourneyTool($this->resource);
    }

    public function test_get_name_returns_customer_purchase_journey(): void
    {
        self::assertSame('customer_purchase_journey', $this->sut->getName());
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
        self::assertContains('first_to_second_product', $aggregationProp['enum']);
        self::assertContains('entry_products_by_ltv', $aggregationProp['enum']);
        self::assertContains('common_sequences', $aggregationProp['enum']);
        self::assertContains('repeat_product_rate', $aggregationProp['enum']);
    }

    public function test_execute_returns_error_for_invalid_aggregation(): void
    {
        $result = json_decode($this->sut->execute(['aggregation' => 'nope']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid aggregation', $result['error']);
    }

    public function test_execute_first_to_second_product_returns_transition_patterns(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'first_sku' => 'SKU-A',
                'first_product_name' => 'Product A',
                'second_sku' => 'SKU-B',
                'second_product_name' => 'Product B',
                'transition_count' => '25',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'first_to_second_product']), true);

        self::assertSame('first_to_second_product', $result['aggregation']);
        self::assertCount(1, $result['rows']);
        self::assertSame('SKU-A', $result['rows'][0]['first_sku']);
        self::assertSame('SKU-B', $result['rows'][0]['second_sku']);
        self::assertSame(25, $result['rows'][0]['transition_count']);
    }

    public function test_execute_entry_products_by_ltv_returns_ltv_per_entry_sku(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'first_sku' => 'ENTRY-001',
                'first_product_name' => 'Entry Product',
                'customer_count' => '100',
                'avg_customer_ltv' => '450.00',
                'total_ltv' => '45000.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'entry_products_by_ltv']), true);

        self::assertSame('entry_products_by_ltv', $result['aggregation']);
        self::assertSame(100, $result['rows'][0]['customer_count']);
        self::assertSame(450.0, $result['rows'][0]['avg_customer_ltv']);
    }

    public function test_execute_repeat_product_rate_returns_percentage(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            [
                'sku' => 'REPEAT-001',
                'product_name' => 'Popular Product',
                'repeat_orders' => '30',
                'total_orders_containing_sku' => '100',
                'repeat_rate_pct' => '30.00',
            ],
        ]);

        $result = json_decode($this->sut->execute(['aggregation' => 'repeat_product_rate']), true);

        self::assertSame('repeat_product_rate', $result['aggregation']);
        self::assertSame(30.0, $result['rows'][0]['repeat_rate_pct']);
        self::assertSame(30, $result['rows'][0]['repeat_orders']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('Timeout'));

        $result = json_decode($this->sut->execute(['aggregation' => 'common_sequences']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Query error', $result['error']);
    }
}
