<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * The store views the migration writes for: the ones the indexer builds collections for (active, Typesense
 * enabled), optionally narrowed to one.
 */
class StoreScope
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /**
     * @return list<Store>
     * @throws LocalizedException when the requested store view is unknown, inactive or has Typesense disabled
     */
    public function getStores(?string $storeCode = null): array
    {
        $stores = [];
        $candidates = $this->storeManager->getStores();
        foreach ($candidates as $store) {
            if (!$store instanceof Store || !$store->isActive() || !$this->config->isEnabled((int) $store->getId())) {
                continue;
            }
            if ($storeCode === null || $store->getCode() === $storeCode) {
                $stores[] = $store;
            }
        }

        if ($storeCode !== null && $stores === []) {
            throw new LocalizedException(
                __('Store view "%1" is not an active store view with Typesense enabled.', $storeCode),
            );
        }

        return $stores;
    }
}
