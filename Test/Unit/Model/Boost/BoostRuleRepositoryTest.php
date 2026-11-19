<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Boost;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Boost\BoostRule;
use RunAsRoot\TypeSense\Model\Boost\BoostRuleFactory;
use RunAsRoot\TypeSense\Model\Boost\BoostRuleRepository;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule as BoostRuleResource;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\Collection;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\CollectionFactory;

final class BoostRuleRepositoryTest extends TestCase
{
    private BoostRuleFactory&MockObject $factory;
    private BoostRuleResource&MockObject $resource;
    private CollectionFactory&MockObject $collectionFactory;
    private BoostRuleRepository $sut;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(BoostRuleFactory::class);
        $this->resource = $this->createMock(BoostRuleResource::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);

        $this->sut = new BoostRuleRepository(
            $this->factory,
            $this->resource,
            $this->collectionFactory,
            $this->createMock(SearchResultsInterfaceFactory::class),
            $this->createMock(CollectionProcessorInterface::class),
        );
    }

    public function test_get_by_id_returns_entity_when_found(): void
    {
        $entity = $this->createMock(BoostRule::class);
        $entity->method('getId')->willReturn(3);
        $this->factory->method('create')->willReturn($entity);
        $this->resource->expects(self::once())->method('load')->with($entity, 3);

        self::assertSame($entity, $this->sut->getById(3));
    }

    public function test_get_by_id_throws_when_not_found(): void
    {
        $entity = $this->createMock(BoostRule::class);
        $entity->method('getId')->willReturn(null);
        $this->factory->method('create')->willReturn($entity);

        $this->expectException(NoSuchEntityException::class);

        $this->sut->getById(99);
    }

    public function test_save_wraps_resource_errors(): void
    {
        $entity = $this->createMock(BoostRule::class);
        $this->resource->method('save')->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotSaveException::class);

        $this->sut->save($entity);
    }

    public function test_get_active_for_store_includes_store_zero_and_filters_dates_and_inactive(): void
    {
        $rules = [7 => $this->createMock(BoostRule::class), 2 => $this->createMock(BoostRule::class)];

        $filters = [];
        $orders = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition) use (&$filters, $collection) {
                $filters[$field] = $condition;

                return $collection;
            },
        );
        $collection->method('setOrder')->willReturnCallback(
            function (string $field, string $direction) use (&$orders, $collection) {
                $orders[] = [$field, $direction];

                return $collection;
            },
        );
        $collection->method('getItems')->willReturn($rules);
        $this->collectionFactory->method('create')->willReturn($collection);

        $result = $this->sut->getActiveForStore(3, '2026-11-19');

        self::assertSame(array_values($rules), $result);
        self::assertSame(
            [
                'store_id' => ['in' => [0, 3]],
                'is_active' => ['eq' => 1],
                'from_date' => [['null' => true], ['lteq' => '2026-11-19']],
                'to_date' => [['null' => true], ['gteq' => '2026-11-19']],
            ],
            $filters,
        );
        self::assertSame([['weight', 'DESC'], ['rule_id', 'ASC']], $orders);
    }

    public function test_get_active_for_admin_store_does_not_duplicate_store_zero(): void
    {
        $filters = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition) use (&$filters, $collection) {
                $filters[$field] = $condition;

                return $collection;
            },
        );
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertSame([], $this->sut->getActiveForStore(0, '2026-11-19'));
        self::assertSame(['in' => [0]], $filters['store_id']);
    }
}
