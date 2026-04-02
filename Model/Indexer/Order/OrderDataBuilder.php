<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class OrderDataBuilder
{
    public function __construct(
        private readonly OrderCollectionFactory $collectionFactory,
        private readonly GroupRepositoryInterface $groupRepository,
    ) {
    }

    /**
     * Build a Typesense document array from an order.
     *
     * @return array<string, mixed>
     */
    public function build(OrderInterface $order, int $storeId): array
    {
        $orderId = (int) $order->getEntityId();
        $customerGroup = $this->resolveCustomerGroup((int) $order->getCustomerGroupId());

        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();
        $payment = $order->getPayment();

        $document = [
            'id' => 'order_' . $orderId,
            'order_id' => $orderId,
            'increment_id' => (string) $order->getIncrementId(),
            'status' => (string) $order->getStatus(),
            'state' => (string) $order->getState(),
            'customer_email' => (string) $order->getCustomerEmail(),
            'customer_name' => trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname()),
            'customer_group' => $customerGroup,
            'grand_total' => (float) $order->getGrandTotal(),
            'subtotal' => (float) $order->getSubtotal(),
            'tax_amount' => (float) $order->getTaxAmount(),
            'shipping_amount' => (float) $order->getShippingAmount(),
            'discount_amount' => (float) $order->getDiscountAmount(),
            'currency_code' => (string) $order->getOrderCurrencyCode(),
            'payment_method' => $payment !== null ? (string) $payment->getMethod() : '',
            'shipping_country' => $shippingAddress !== null ? (string) $shippingAddress->getCountryId() : '',
            'shipping_region' => $shippingAddress !== null ? (string) $shippingAddress->getRegion() : '',
            'shipping_city' => $shippingAddress !== null ? (string) $shippingAddress->getCity() : '',
            'shipping_method' => (string) $order->getShippingDescription(),
            'billing_country' => $billingAddress !== null ? (string) $billingAddress->getCountryId() : '',
            'billing_region' => $billingAddress !== null ? (string) $billingAddress->getRegion() : '',
            'created_at' => $this->toTimestamp((string) $order->getCreatedAt()),
            'updated_at' => $this->toTimestamp((string) $order->getUpdatedAt()),
            'store_id' => $storeId,
        ];

        [$itemCount, $itemSkus, $itemNames] = $this->extractItems($order);
        $document['item_count'] = $itemCount;
        $document['item_skus'] = $itemSkus;
        $document['item_names'] = $itemNames;

        return $document;
    }

    /**
     * Load an order collection filtered by entity IDs.
     *
     * @param int[] $entityIds
     */
    public function getOrderCollection(array $entityIds, int $storeId): OrderCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);

        if ($entityIds !== []) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
    }

    /**
     * @return array{int, string[], string[]}
     */
    private function extractItems(OrderInterface $order): array
    {
        $items = $order->getItems();
        if ($items === null) {
            return [0, [], []];
        }

        $skus = [];
        $names = [];

        foreach ($items as $item) {
            // Skip child items (e.g. configurable product children)
            if ($item->getParentItemId() !== null) {
                continue;
            }

            $skus[] = (string) $item->getSku();
            $names[] = (string) $item->getName();
        }

        return [count($skus), $skus, $names];
    }

    private function resolveCustomerGroup(int $groupId): string
    {
        try {
            $group = $this->groupRepository->getById($groupId);
            return (string) $group->getCode();
        } catch (\Exception) {
            return '';
        }
    }

    private function toTimestamp(string $dateString): int
    {
        if ($dateString === '') {
            return 0;
        }

        $ts = strtotime($dateString);

        return $ts !== false ? $ts : 0;
    }
}
