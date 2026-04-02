<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Customer;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class CustomerDataBuilder
{
    private const GENDER_MAP = [
        1 => 'Male',
        2 => 'Female',
        3 => 'Not Specified',
    ];

    public function __construct(
        private readonly CustomerCollectionFactory $collectionFactory,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory,
    ) {
    }

    /**
     * Build a Typesense document array from a customer.
     *
     * @return array<string, mixed>
     */
    public function build(Customer $customer, int $storeId): array
    {
        $customerId = (int) $customer->getId();
        $groupName = $this->resolveGroupName((int) $customer->getGroupId());
        [$orderCount, $lifetimeValue, $lastOrderDate] = $this->resolveOrderStats($customerId);

        $document = [
            'id' => 'customer_' . $customerId,
            'customer_id' => $customerId,
            'email' => (string) $customer->getEmail(),
            'firstname' => (string) $customer->getFirstname(),
            'lastname' => (string) $customer->getLastname(),
            'group_id' => (int) $customer->getGroupId(),
            'group_name' => $groupName,
            'created_at' => $this->toTimestamp((string) $customer->getCreatedAt()),
            'updated_at' => $this->toTimestamp((string) $customer->getUpdatedAt()),
            'order_count' => $orderCount,
            'lifetime_value' => $lifetimeValue,
            'store_id' => $storeId,
            'website_id' => (int) $customer->getWebsiteId(),
            'is_active' => true,
        ];

        $dob = $customer->getDob();
        if ($dob !== null && $dob !== '') {
            $document['dob'] = $dob;
        }

        $genderCode = $customer->getGender();
        if ($genderCode !== null) {
            $genderInt = (int) $genderCode;
            if (isset(self::GENDER_MAP[$genderInt])) {
                $document['gender'] = self::GENDER_MAP[$genderInt];
            }
        }

        if ($lastOrderDate !== null) {
            $document['last_order_date'] = $lastOrderDate;
        }

        $this->appendAddressData($customer, $document);

        return $document;
    }

    /**
     * Load a customer collection filtered by store and optional entity IDs.
     *
     * @param int[] $entityIds
     */
    public function getCustomerCollection(array $entityIds, int $storeId): CustomerCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);

        if (!empty($entityIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
    }

    /**
     * @return array{int, float, int|null}
     */
    private function resolveOrderStats(int $customerId): array
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);

        $collection->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns([
                'order_count' => new \Zend_Db_Expr('COUNT(*)'),
                'lifetime_value' => new \Zend_Db_Expr('COALESCE(SUM(grand_total), 0)'),
                'last_order_date' => new \Zend_Db_Expr('MAX(created_at)'),
            ]);

        $row = $collection->getConnection()->fetchRow($collection->getSelect());

        $lastOrderDate = null;
        if (!empty($row['last_order_date'])) {
            $lastOrderDate = $this->toTimestamp($row['last_order_date']);
        }

        return [(int) ($row['order_count'] ?? 0), (float) ($row['lifetime_value'] ?? 0.0), $lastOrderDate];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function appendAddressData(Customer $customer, array &$document): void
    {
        $addresses = $customer->getAddresses();
        if (empty($addresses)) {
            return;
        }

        $defaultBillingId = $customer->getDefaultBilling();
        $defaultShippingId = $customer->getDefaultShipping();

        foreach ($addresses as $address) {
            $addressId = (string) $address->getId();

            if ($defaultBillingId !== null && $addressId === (string) $defaultBillingId) {
                $country = (string) $address->getCountryId();
                $region = (string) $address->getRegion();
                $city = (string) $address->getCity();

                if ($country !== '') {
                    $document['default_billing_country'] = $country;
                }
                if ($region !== '') {
                    $document['default_billing_region'] = $region;
                }
                if ($city !== '') {
                    $document['default_billing_city'] = $city;
                }
            }

            if ($defaultShippingId !== null && $addressId === (string) $defaultShippingId) {
                $country = (string) $address->getCountryId();
                $region = (string) $address->getRegion();
                $city = (string) $address->getCity();

                if ($country !== '') {
                    $document['default_shipping_country'] = $country;
                }
                if ($region !== '') {
                    $document['default_shipping_region'] = $region;
                }
                if ($city !== '') {
                    $document['default_shipping_city'] = $city;
                }
            }
        }
    }

    private function resolveGroupName(int $groupId): string
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
