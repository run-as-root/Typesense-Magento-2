<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\CategoryPositionResolver;

final class CategoryPositionResolverTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private CategoryPositionResolver $sut;

    protected function setUp(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('join')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($this->select);

        $this->sut = new CategoryPositionResolver($resource);
    }

    public function test_schema_declares_one_sortable_optional_int_field_per_category_by_pattern(): void
    {
        self::assertSame(
            ['name' => 'category_position_.*', 'type' => 'int32', 'optional' => true, 'sort' => true],
            $this->sut->getSchemaField(),
        );
        self::assertSame('category_position_42', CategoryPositionResolver::fieldName(42));
    }

    public function test_assigned_categories_keep_their_magento_position(): void
    {
        $this->select->expects(self::once())->method('where')->with('ccp.product_id IN (?)', [7, 8]);
        $this->connection->method('fetchAll')->willReturn([
            ['product_id' => '7', 'category_id' => '10', 'position' => '3', 'path' => '1/2/10'],
            ['product_id' => '8', 'category_id' => '10', 'position' => '0', 'path' => '1/2/10'],
        ]);

        // The ancestors (root catalog included) are dropped later by toFields(), unless the document lists them.
        self::assertSame(
            [7 => [10 => 3, 1 => 10003, 2 => 10003], 8 => [10 => 0, 1 => 10000, 2 => 10000]],
            $this->sut->load([7, 8]),
        );
    }

    public function test_ancestors_get_the_lowest_descendant_position_after_their_assigned_products(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['product_id' => '7', 'category_id' => '11', 'position' => '4', 'path' => '1/2/10/11'],
            ['product_id' => '7', 'category_id' => '12', 'position' => '2', 'path' => '1/2/10/12'],
            ['product_id' => '7', 'category_id' => '2', 'position' => '9', 'path' => '1/2'],
        ]);

        $positions = $this->sut->load([7]);

        // Magento's anchor index: IFNULL(assigned position, MIN(descendant position) + 10000).
        self::assertSame(10002, $positions[7][10]);
        self::assertSame(9, $positions[7][2]);
        self::assertSame(4, $positions[7][11]);
        self::assertSame(2, $positions[7][12]);
    }

    public function test_no_query_without_products(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        self::assertSame([], $this->sut->load([]));
    }

    public function test_fields_are_written_for_the_document_categories_only(): void
    {
        self::assertSame(
            ['category_position_10' => 10002, 'category_position_11' => 4],
            $this->sut->toFields([10 => 10002, 11 => 4, 1 => 10003, 99 => 5], [10, 11, 12]),
        );
    }
}
