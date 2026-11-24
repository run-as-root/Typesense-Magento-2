<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\AttributeResolverInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\Resolver\CompositeChildrenDataResolver;

final class CompositeChildrenDataResolverTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private ProductCollectionFactory&MockObject $collectionFactory;
    private ProductCollection&MockObject $collection;
    private AttributeResolverInterface&MockObject $attributeResolver;
    /** @var array<string, mixed> */
    private array $collectionFilters = [];
    /** @var array<int, array<string, list<string>>> attribute values by child id */
    private array $childAttributes = [];
    private CompositeChildrenDataResolver $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnArgument(0);
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($this->select);

        $this->collection = $this->createMock(ProductCollection::class);
        $this->collection->method('addAttributeToFilter')->willReturnCallback(
            function (string $attribute, mixed $condition): ProductCollection {
                $this->collectionFilters[$attribute] = $condition;

                return $this->collection;
            },
        );
        $this->collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, mixed $condition): ProductCollection {
                $this->collectionFilters[$field] = $condition;

                return $this->collection;
            },
        );
        $this->collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->attributeResolver = $this->createMock(AttributeResolverInterface::class);
        $this->attributeResolver->method('getExtraAttributes')->willReturnCallback(
            fn (Product $product): array => $this->childAttributes[(int) $product->getId()] ?? [],
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(2)->willReturn($store);

        $this->sut = new CompositeChildrenDataResolver(
            $this->resource,
            $this->collectionFactory,
            $this->attributeResolver,
            $storeManager,
        );
    }

    public function test_preload_resolves_every_parent_of_the_batch_with_one_relation_query_and_one_collection(): void
    {
        $this->select->expects(self::once())->method('where')->with('parent_id IN (?)', [10, 11, 12])->willReturnSelf();
        $this->connection->expects(self::once())->method('fetchAll')->willReturn([
            ['parent_id' => '10', 'child_id' => '100'],
            ['parent_id' => '10', 'child_id' => '101'],
            ['parent_id' => '11', 'child_id' => '100'],
            // Disabled or not in the website: the collection does not return it.
            ['parent_id' => '11', 'child_id' => '102'],
        ]);
        $first = $this->createChild(100, 'Birne 0,02l', 'B-002', ['trade_name' => ['Likör']]);
        $second = $this->createChild(101, 'Birne 0,5l', 'B-05', ['trade_name' => ['Edelbrand']]);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$first, $second]));
        $this->collectionFactory->expects(self::once())->method('create');

        $this->sut->preload([10, 11, 12], 2);

        self::assertSame([
            ['name' => 'Birne 0,02l', 'sku' => 'B-002', 'attributes' => ['trade_name' => ['Likör']]],
            ['name' => 'Birne 0,5l', 'sku' => 'B-05', 'attributes' => ['trade_name' => ['Edelbrand']]],
        ], $this->sut->getChildren(10, 2));
        self::assertSame(
            [['name' => 'Birne 0,02l', 'sku' => 'B-002', 'attributes' => ['trade_name' => ['Likör']]]],
            $this->sut->getChildren(11, 2),
        );
        self::assertSame([], $this->sut->getChildren(12, 2));
        self::assertSame(['in' => [100, 101, 102]], $this->collectionFilters['entity_id']);
    }

    public function test_children_are_enabled_products_of_the_store_website_in_any_visibility(): void
    {
        $this->connection->method('fetchAll')->willReturn([['parent_id' => '10', 'child_id' => '100']]);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collection->expects(self::once())->method('setStoreId')->with(2);
        $this->collection->expects(self::once())->method('addWebsiteFilter')->with(3);

        $this->sut->preload([10], 2);

        self::assertSame(['eq' => Status::STATUS_ENABLED], $this->collectionFilters['status']);
        self::assertArrayNotHasKey('visibility', $this->collectionFilters);
    }

    public function test_a_parent_outside_the_preloaded_batch_is_loaded_on_its_own(): void
    {
        $this->select->expects(self::once())->method('where')->with('parent_id IN (?)', [10])->willReturnSelf();
        $this->connection->method('fetchAll')->willReturn([['parent_id' => '10', 'child_id' => '100']]);
        $child = $this->createChild(100, 'Birne 0,02l', 'B-002', []);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$child]));

        self::assertSame(
            [['name' => 'Birne 0,02l', 'sku' => 'B-002', 'attributes' => []]],
            $this->sut->getChildren(10, 2),
        );
    }

    public function test_parents_without_children_load_no_collection(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $this->collectionFactory->expects(self::never())->method('create');

        $this->sut->preload([10], 2);

        self::assertSame([], $this->sut->getChildren(10, 2));
    }

    public function test_preload_without_parents_queries_nothing(): void
    {
        $this->resource->expects(self::never())->method('getConnection');

        $this->sut->preload([], 2);
    }

    /** @param array<string, list<string>> $attributes */
    private function createChild(int $id, string $name, string $sku, array $attributes): Product&MockObject
    {
        $child = $this->createMock(Product::class);
        $child->method('getId')->willReturn($id);
        $child->method('getName')->willReturn($name);
        $child->method('getSku')->willReturn($sku);
        $this->childAttributes[$id] = $attributes;

        return $child;
    }
}
