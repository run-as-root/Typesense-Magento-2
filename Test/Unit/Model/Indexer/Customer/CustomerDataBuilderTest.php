<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Customer;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Customer\CustomerDataBuilder;

final class CustomerDataBuilderTest extends TestCase
{
    private CustomerCollectionFactory&MockObject $collectionFactory;
    private GroupRepositoryInterface&MockObject $groupRepository;
    private OrderCollectionFactory&MockObject $orderCollectionFactory;
    private CustomerDataBuilder $sut;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CustomerCollectionFactory::class);
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);

        $this->sut = new CustomerDataBuilder(
            $this->collectionFactory,
            $this->groupRepository,
            $this->orderCollectionFactory,
        );
    }

    public function test_build_returns_complete_document(): void
    {
        $customer = $this->createCustomerMock();

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->with(1)->willReturn($group);

        $this->mockOrderStats(customerId: 10, orderCount: 2, total: 199.98, lastDate: '2024-06-01 10:00:00');

        $document = $this->sut->build($customer, 1);

        self::assertSame('customer_10', $document['id']);
        self::assertSame(10, $document['customer_id']);
        self::assertSame('john.doe@example.com', $document['email']);
        self::assertSame('John', $document['firstname']);
        self::assertSame('Doe', $document['lastname']);
        self::assertSame(1, $document['group_id']);
        self::assertSame('General', $document['group_name']);
        self::assertIsInt($document['created_at']);
        self::assertIsInt($document['updated_at']);
        self::assertSame(2, $document['order_count']);
        self::assertSame(199.98, $document['lifetime_value']);
        self::assertIsInt($document['last_order_date']);
        self::assertSame(1, $document['store_id']);
        self::assertSame(1, $document['website_id']);
        self::assertTrue($document['is_active']);
    }

    public function test_build_maps_gender_male(): void
    {
        $customer = $this->createCustomerMock(gender: 1);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertSame('Male', $document['gender']);
    }

    public function test_build_maps_gender_female(): void
    {
        $customer = $this->createCustomerMock(gender: 2);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertSame('Female', $document['gender']);
    }

    public function test_build_maps_gender_not_specified(): void
    {
        $customer = $this->createCustomerMock(gender: 3);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertSame('Not Specified', $document['gender']);
    }

    public function test_build_omits_gender_when_null(): void
    {
        $customer = $this->createCustomerMock(gender: null);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertArrayNotHasKey('gender', $document);
    }

    public function test_build_includes_dob_when_present(): void
    {
        $customer = $this->createCustomerMock(dob: '1990-01-15');
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertSame('1990-01-15', $document['dob']);
    }

    public function test_build_omits_dob_when_null(): void
    {
        $customer = $this->createCustomerMock(dob: null);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertArrayNotHasKey('dob', $document);
    }

    public function test_build_includes_billing_address_fields(): void
    {
        $customer = $this->createCustomerMock(withBillingAddress: true);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertSame('US', $document['default_billing_country']);
        self::assertSame('California', $document['default_billing_region']);
        self::assertSame('Los Angeles', $document['default_billing_city']);
    }

    public function test_build_includes_shipping_address_fields(): void
    {
        $customer = $this->createCustomerMock(withShippingAddress: true);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertSame('US', $document['default_shipping_country']);
        self::assertSame('California', $document['default_shipping_region']);
        self::assertSame('San Francisco', $document['default_shipping_city']);
    }

    public function test_build_omits_address_fields_when_no_addresses(): void
    {
        $customer = $this->createCustomerMock(withBillingAddress: false, withShippingAddress: false);
        $this->stubGroupAndOrders();

        $document = $this->sut->build($customer, 1);

        self::assertArrayNotHasKey('default_billing_country', $document);
        self::assertArrayNotHasKey('default_shipping_country', $document);
    }

    public function test_build_handles_group_repository_exception(): void
    {
        $customer = $this->createCustomerMock();
        $this->groupRepository->method('getById')->willThrowException(new \Exception('Group not found'));
        $this->mockOrderStats(customerId: 10, orderCount: 0, total: 0.0);

        $document = $this->sut->build($customer, 1);

        self::assertSame('', $document['group_name']);
    }

    public function test_build_omits_last_order_date_when_no_orders(): void
    {
        $customer = $this->createCustomerMock();
        $this->stubGroupAndOrders(orderCount: 0);

        $document = $this->sut->build($customer, 1);

        self::assertArrayNotHasKey('last_order_date', $document);
        self::assertSame(0, $document['order_count']);
        self::assertSame(0.0, $document['lifetime_value']);
    }

    public function test_get_customer_collection_filters_by_store_id(): void
    {
        $collection = $this->createMock(CustomerCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::atLeastOnce())
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection): CustomerCollection {
                return $collection;
            });

        $this->sut->getCustomerCollection([], 2);
    }

    public function test_get_customer_collection_filters_by_entity_ids_when_provided(): void
    {
        $collection = $this->createMock(CustomerCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $callArgs = [];
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection, &$callArgs): CustomerCollection {
                $callArgs[] = $field;
                return $collection;
            });

        $this->sut->getCustomerCollection([1, 2, 3], 1);

        self::assertContains('entity_id', $callArgs);
    }

    public function test_get_customer_collection_does_not_filter_entity_ids_when_empty(): void
    {
        $collection = $this->createMock(CustomerCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $callArgs = [];
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection, &$callArgs): CustomerCollection {
                $callArgs[] = $field;
                return $collection;
            });

        $this->sut->getCustomerCollection([], 1);

        self::assertNotContains('entity_id', $callArgs);
    }

    private function stubGroupAndOrders(int $orderCount = 0): void
    {
        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $this->mockOrderStats(customerId: 10, orderCount: $orderCount, total: 0.0);
    }

    private function mockOrderStats(int $customerId, int $orderCount, float $total, string $lastDate = ''): void
    {
        $orders = [];
        if ($orderCount > 0) {
            $perOrder = round($total / $orderCount, 2);
            for ($i = 0; $i < $orderCount; $i++) {
                $order = $this->createMock(Order::class);
                $order->method('getGrandTotal')->willReturn((string) $perOrder);
                $order->method('getCreatedAt')->willReturn($lastDate ?: '2024-01-01 00:00:00');
                $orders[] = $order;
            }
        }

        $orderCollection = $this->createMock(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();

        // Make collection iterable
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);
    }

    /**
     * @return CustomerInterface&MockObject
     */
    private function createCustomerMock(
        int|null $gender = 1,
        string|null $dob = '1990-05-20',
        bool $withBillingAddress = true,
        bool $withShippingAddress = true,
    ): CustomerInterface&MockObject {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn('10');
        $customer->method('getEmail')->willReturn('john.doe@example.com');
        $customer->method('getFirstname')->willReturn('John');
        $customer->method('getLastname')->willReturn('Doe');
        $customer->method('getGroupId')->willReturn('1');
        $customer->method('getCreatedAt')->willReturn('2023-01-01 00:00:00');
        $customer->method('getUpdatedAt')->willReturn('2024-01-01 00:00:00');
        $customer->method('getWebsiteId')->willReturn('1');
        $customer->method('getGender')->willReturn($gender !== null ? (string) $gender : null);
        $customer->method('getDob')->willReturn($dob);

        $addresses = [];

        if ($withBillingAddress) {
            $billingRegion = $this->createMock(RegionInterface::class);
            $billingRegion->method('getRegion')->willReturn('California');

            $billingAddress = $this->createMock(AddressInterface::class);
            $billingAddress->method('getId')->willReturn('100');
            $billingAddress->method('getCountryId')->willReturn('US');
            $billingAddress->method('getRegion')->willReturn($billingRegion);
            $billingAddress->method('getCity')->willReturn('Los Angeles');
            $addresses[] = $billingAddress;

            $customer->method('getDefaultBilling')->willReturn('100');
        } else {
            $customer->method('getDefaultBilling')->willReturn(null);
        }

        if ($withShippingAddress) {
            $shippingRegion = $this->createMock(RegionInterface::class);
            $shippingRegion->method('getRegion')->willReturn('California');

            $shippingAddress = $this->createMock(AddressInterface::class);
            $shippingAddress->method('getId')->willReturn('101');
            $shippingAddress->method('getCountryId')->willReturn('US');
            $shippingAddress->method('getRegion')->willReturn($shippingRegion);
            $shippingAddress->method('getCity')->willReturn('San Francisco');
            $addresses[] = $shippingAddress;

            $customer->method('getDefaultShipping')->willReturn('101');
        } else {
            $customer->method('getDefaultShipping')->willReturn(null);
        }

        $customer->method('getAddresses')->willReturn($addresses ?: null);

        return $customer;
    }
}
