<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\CustomerSegmentationTool;

final class CustomerSegmentationToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private CustomerSegmentationTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new CustomerSegmentationTool($this->resource);
    }

    public function test_get_name_returns_customer_segmentation(): void
    {
        self::assertSame('customer_segmentation', $this->sut->getName());
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

    public function test_execute_returns_empty_message_when_no_customers(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertArrayHasKey('total_customers', $result);
        self::assertSame(0, $result['total_customers']);
    }

    public function test_execute_returns_segment_summary(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // Mock customers with varying RFM values
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'recency_days' => 5, 'frequency' => 10, 'monetary' => 5000.00],
            ['entity_id' => 2, 'recency_days' => 5, 'frequency' => 8, 'monetary' => 4000.00],
            ['entity_id' => 3, 'recency_days' => 10, 'frequency' => 5, 'monetary' => 2000.00],
            ['entity_id' => 4, 'recency_days' => 200, 'frequency' => 1, 'monetary' => 100.00],
            ['entity_id' => 5, 'recency_days' => 9999, 'frequency' => 0, 'monetary' => 0.00],
        ]);

        $result = json_decode($this->sut->execute([]), true);

        self::assertArrayHasKey('total_customers', $result);
        self::assertSame(5, $result['total_customers']);
        self::assertArrayHasKey('segments', $result);
        self::assertNotEmpty($result['segments']);

        // Each segment should have the expected structure
        foreach ($result['segments'] as $segment) {
            self::assertArrayHasKey('segment', $segment);
            self::assertArrayHasKey('customer_count', $segment);
            self::assertArrayHasKey('avg_lifetime_value', $segment);
            self::assertArrayHasKey('avg_order_count', $segment);
        }
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
