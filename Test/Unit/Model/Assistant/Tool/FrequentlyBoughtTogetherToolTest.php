<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\FrequentlyBoughtTogetherTool;

final class FrequentlyBoughtTogetherToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private FrequentlyBoughtTogetherTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new FrequentlyBoughtTogetherTool($this->resource);
    }

    public function test_get_name_returns_frequently_bought_together(): void
    {
        self::assertSame('frequently_bought_together', $this->sut->getName());
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

        self::assertArrayHasKey('product_sku', $schema['properties']);
        self::assertArrayHasKey('min_occurrences', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
    }

    public function test_execute_returns_pairs_from_database(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $connection->method('fetchAll')->willReturn([
            ['product_a_name' => 'Widget A', 'sku_a' => 'SKU-A', 'product_b_name' => 'Widget B', 'sku_b' => 'SKU-B', 'times_bought_together' => 5],
            ['product_a_name' => 'Widget A', 'sku_a' => 'SKU-A', 'product_b_name' => 'Widget C', 'sku_b' => 'SKU-C', 'times_bought_together' => 3],
        ]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertArrayHasKey('pairs', $result);
        self::assertSame(2, $result['count']);
        self::assertSame('SKU-A', $result['pairs'][0]['sku_a']);
    }

    public function test_execute_passes_sku_filter(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $capturedSql = '';
        $connection->method('fetchAll')
            ->willReturnCallback(function ($sql, $params) use (&$capturedSql) {
                $capturedSql = $sql;
                return [];
            });

        $this->sut->execute(['product_sku' => 'SKU-001']);

        self::assertStringContainsString('a.sku = ?', $capturedSql);
    }

    public function test_execute_without_sku_filter(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        $capturedSql = '';
        $connection->method('fetchAll')
            ->willReturnCallback(function ($sql, $params) use (&$capturedSql) {
                $capturedSql = $sql;
                return [];
            });

        $this->sut->execute([]);

        self::assertStringNotContainsString('a.sku = ?', $capturedSql);
        self::assertNull(json_decode($this->sut->execute([]), true)['product_sku_filter']);
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
