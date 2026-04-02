<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Order;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Order\OrderDataBuilder;

final class OrderDataBuilderTest extends TestCase
{
    private OrderCollectionFactory&MockObject $collectionFactory;
    private GroupRepositoryInterface&MockObject $groupRepository;
    private OrderDataBuilder $sut;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);

        $this->sut = new OrderDataBuilder(
            $this->collectionFactory,
            $this->groupRepository,
        );
    }

    public function test_build_returns_complete_document(): void
    {
        $order = $this->createOrderMock();

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->with(1)->willReturn($group);

        $document = $this->sut->build($order, 1);

        self::assertSame('order_42', $document['id']);
        self::assertSame(42, $document['order_id']);
        self::assertSame('000000042', $document['increment_id']);
        self::assertSame('processing', $document['status']);
        self::assertSame('processing', $document['state']);
        self::assertSame('customer@example.com', $document['customer_email']);
        self::assertSame('John Doe', $document['customer_name']);
        self::assertSame('General', $document['customer_group']);
        self::assertSame(99.99, $document['grand_total']);
        self::assertSame(89.99, $document['subtotal']);
        self::assertSame(5.00, $document['tax_amount']);
        self::assertSame(10.00, $document['shipping_amount']);
        self::assertSame(5.00, $document['discount_amount']);
        self::assertSame('USD', $document['currency_code']);
        self::assertSame('checkmo', $document['payment_method']);
        self::assertSame('US', $document['shipping_country']);
        self::assertSame('California', $document['shipping_region']);
        self::assertSame('Los Angeles', $document['shipping_city']);
        self::assertSame('Flat Rate - Fixed', $document['shipping_method']);
        self::assertSame('US', $document['billing_country']);
        self::assertSame('California', $document['billing_region']);
        self::assertSame(1, $document['store_id']);
        self::assertSame(1, $document['item_count']);
        self::assertSame(['SKU-001'], $document['item_skus']);
        self::assertSame(['Test Product'], $document['item_names']);
        self::assertIsInt($document['created_at']);
        self::assertIsInt($document['updated_at']);
    }

    public function test_build_skips_child_items(): void
    {
        $order = $this->createOrderMock(withChildItem: true);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $document = $this->sut->build($order, 1);

        // Only the parent item should be counted, not the child
        self::assertSame(1, $document['item_count']);
        self::assertCount(1, $document['item_skus']);
    }

    public function test_build_handles_null_shipping_address(): void
    {
        $order = $this->createOrderMock(withShippingAddress: false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $document = $this->sut->build($order, 1);

        self::assertSame('', $document['shipping_country']);
        self::assertSame('', $document['shipping_region']);
        self::assertSame('', $document['shipping_city']);
    }

    public function test_build_handles_null_billing_address(): void
    {
        $order = $this->createOrderMock(withBillingAddress: false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $document = $this->sut->build($order, 1);

        self::assertSame('', $document['billing_country']);
        self::assertSame('', $document['billing_region']);
    }

    public function test_build_handles_null_payment(): void
    {
        $order = $this->createOrderMock(withPayment: false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $document = $this->sut->build($order, 1);

        self::assertSame('', $document['payment_method']);
    }

    public function test_build_handles_group_repository_exception(): void
    {
        $order = $this->createOrderMock();

        $this->groupRepository->method('getById')->willThrowException(new \Exception('Group not found'));

        $document = $this->sut->build($order, 1);

        self::assertSame('', $document['customer_group']);
    }

    public function test_get_order_collection_filters_by_store_id(): void
    {
        $collection = $this->createMock(OrderCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::atLeastOnce())
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection): OrderCollection {
                return $collection;
            });

        $this->sut->getOrderCollection([], 2);
    }

    public function test_get_order_collection_filters_by_entity_ids_when_provided(): void
    {
        $collection = $this->createMock(OrderCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $callArgs = [];
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection, &$callArgs): OrderCollection {
                $callArgs[] = $field;
                return $collection;
            });

        $this->sut->getOrderCollection([1, 2, 3], 1);

        self::assertContains('entity_id', $callArgs);
    }

    public function test_get_order_collection_does_not_filter_entity_ids_when_empty(): void
    {
        $collection = $this->createMock(OrderCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $callArgs = [];
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection, &$callArgs): OrderCollection {
                $callArgs[] = $field;
                return $collection;
            });

        $this->sut->getOrderCollection([], 1);

        self::assertNotContains('entity_id', $callArgs);
    }

    /**
     * @return OrderInterface&MockObject
     */
    private function createOrderMock(
        bool $withShippingAddress = true,
        bool $withBillingAddress = true,
        bool $withPayment = true,
        bool $withChildItem = false,
    ): OrderInterface&MockObject {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn('42');
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getState')->willReturn('processing');
        $order->method('getCustomerEmail')->willReturn('customer@example.com');
        $order->method('getCustomerFirstname')->willReturn('John');
        $order->method('getCustomerLastname')->willReturn('Doe');
        $order->method('getCustomerGroupId')->willReturn('1');
        $order->method('getGrandTotal')->willReturn('99.99');
        $order->method('getSubtotal')->willReturn('89.99');
        $order->method('getTaxAmount')->willReturn('5.00');
        $order->method('getShippingAmount')->willReturn('10.00');
        $order->method('getDiscountAmount')->willReturn('5.00');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getShippingDescription')->willReturn('Flat Rate - Fixed');
        $order->method('getCreatedAt')->willReturn('2024-01-15 10:00:00');
        $order->method('getUpdatedAt')->willReturn('2024-01-15 12:00:00');

        if ($withShippingAddress) {
            $shippingAddress = $this->createMock(OrderAddressInterface::class);
            $shippingAddress->method('getCountryId')->willReturn('US');
            $shippingAddress->method('getRegion')->willReturn('California');
            $shippingAddress->method('getCity')->willReturn('Los Angeles');
            $order->method('getShippingAddress')->willReturn($shippingAddress);
        } else {
            $order->method('getShippingAddress')->willReturn(null);
        }

        if ($withBillingAddress) {
            $billingAddress = $this->createMock(OrderAddressInterface::class);
            $billingAddress->method('getCountryId')->willReturn('US');
            $billingAddress->method('getRegion')->willReturn('California');
            $order->method('getBillingAddress')->willReturn($billingAddress);
        } else {
            $order->method('getBillingAddress')->willReturn(null);
        }

        if ($withPayment) {
            $payment = $this->createMock(OrderPaymentInterface::class);
            $payment->method('getMethod')->willReturn('checkmo');
            $order->method('getPayment')->willReturn($payment);
        } else {
            $order->method('getPayment')->willReturn(null);
        }

        $parentItem = $this->createMock(OrderItemInterface::class);
        $parentItem->method('getParentItemId')->willReturn(null);
        $parentItem->method('getSku')->willReturn('SKU-001');
        $parentItem->method('getName')->willReturn('Test Product');

        $items = [$parentItem];

        if ($withChildItem) {
            $childItem = $this->createMock(OrderItemInterface::class);
            $childItem->method('getParentItemId')->willReturn('1');
            $childItem->method('getSku')->willReturn('SKU-001-RED');
            $childItem->method('getName')->willReturn('Test Product - Red');
            $items[] = $childItem;
        }

        $order->method('getItems')->willReturn($items);

        return $order;
    }
}
