<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Store;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\GroupRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

class StoreDataBuilder
{
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Build a Typesense document array from a store.
     *
     * @return array<string, mixed>
     */
    public function build(StoreInterface $store, int $storeId): array
    {
        $storeIdInt = (int) $store->getId();

        try {
            $website = $this->websiteRepository->getById((int) $store->getWebsiteId());
            $websiteCode = (string) $website->getCode();
            $websiteName = (string) $website->getName();
        } catch (\Exception) {
            $websiteCode = '';
            $websiteName = '';
        }

        try {
            $group = $this->groupRepository->get((int) $store->getStoreGroupId());
            $groupName = (string) $group->getName();
            $rootCategoryId = (int) $group->getRootCategoryId();
        } catch (\Exception) {
            $groupName = '';
            $rootCategoryId = 0;
        }

        $baseUrl = (string) $this->scopeConfig->getValue(
            'web/unsecure/base_url',
            ScopeInterface::SCOPE_STORE,
            $storeIdInt,
        );

        $baseCurrency = (string) $this->scopeConfig->getValue(
            'currency/options/base',
            ScopeInterface::SCOPE_STORE,
            $storeIdInt,
        );

        $defaultLocale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeIdInt,
        );

        return [
            'id' => 'store_' . $storeIdInt,
            'store_id' => $storeIdInt,
            'store_code' => (string) $store->getCode(),
            'store_name' => (string) $store->getName(),
            'website_id' => (int) $store->getWebsiteId(),
            'website_code' => $websiteCode,
            'website_name' => $websiteName,
            'group_id' => (int) $store->getStoreGroupId(),
            'group_name' => $groupName,
            'root_category_id' => $rootCategoryId,
            'base_url' => $baseUrl,
            'base_currency' => $baseCurrency,
            'default_locale' => $defaultLocale,
            'is_active' => (bool) $store->isActive(),
        ];
    }

    /**
     * Return store objects to index.
     *
     * If $entityIds is empty, all stores except 'admin' are returned.
     * Otherwise only the stores matching the given IDs are returned.
     *
     * @param int[] $entityIds
     * @return StoreInterface[]
     */
    public function getStores(array $entityIds, int $storeId): array
    {
        $allStores = $this->storeRepository->getList();

        $stores = array_filter(
            $allStores,
            static fn(StoreInterface $store): bool => $store->getCode() !== 'admin',
        );

        if ($entityIds === []) {
            return array_values($stores);
        }

        return array_values(
            array_filter(
                $stores,
                static fn(StoreInterface $store): bool => in_array((int) $store->getId(), $entityIds, true),
            )
        );
    }
}
