<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product\Link;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\Resolver\CrossSellResolver;

final class CrossSellResolverTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    /** @var list<array{0: string, 1: mixed}> */
    private array $conditions = [];
    private CrossSellResolver $sut;

    protected function setUp(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('joinLeft')->willReturnSelf();
        $this->select->method('where')->willReturnCallback(function (string $condition, mixed $value): Select {
            $this->conditions[] = [$condition, $value];

            return $this->select;
        });
        $this->connection->method('select')->willReturn($this->select);

        $this->sut = new CrossSellResolver($resource);
    }

    public function test_preload_resolves_the_cross_sells_of_the_whole_batch_with_one_query_in_position_order(): void
    {
        $this->connection->expects(self::once())->method('fetchAll')->willReturn([
            ['product_id' => '10', 'linked_product_id' => '30', 'position' => '2'],
            ['product_id' => '10', 'linked_product_id' => '31', 'position' => '1'],
            ['product_id' => '11', 'linked_product_id' => '40', 'position' => null],
            ['product_id' => '10', 'linked_product_id' => '29', 'position' => '2'],
        ]);

        $this->sut->preload([10, 11, 12]);

        self::assertSame([31, 29, 30], $this->sut->getCrossSellIds(10));
        self::assertSame([40], $this->sut->getCrossSellIds(11));
        self::assertSame([], $this->sut->getCrossSellIds(12));
        self::assertContains(['link.link_type_id = ?', Link::LINK_TYPE_CROSSSELL], $this->conditions);
        self::assertContains(['link.product_id IN (?)', [10, 11, 12]], $this->conditions);
    }

    public function test_a_product_outside_the_preloaded_batch_is_loaded_on_its_own(): void
    {
        $this->connection->expects(self::exactly(2))->method('fetchAll')->willReturnOnConsecutiveCalls(
            [],
            [['product_id' => '20', 'linked_product_id' => '21', 'position' => '0']],
        );

        $this->sut->preload([10]);

        self::assertSame([], $this->sut->getCrossSellIds(10));
        self::assertSame([21], $this->sut->getCrossSellIds(20));
        self::assertSame([21], $this->sut->getCrossSellIds(20));
    }

    public function test_an_empty_batch_queries_nothing(): void
    {
        $this->connection->expects(self::never())->method('fetchAll');

        $this->sut->preload([]);
    }
}
