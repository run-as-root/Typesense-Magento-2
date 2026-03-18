<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class TypeSenseConfig implements TypeSenseConfigInterface
{
    private const string CONFIG_PREFIX = 'run_as_root_typesense';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('general/enabled', $storeId);
    }

    public function getProtocol(?int $storeId = null): string
    {
        return (string) $this->getValue('general/protocol', $storeId);
    }

    public function getHost(?int $storeId = null): string
    {
        return (string) $this->getValue('general/host', $storeId);
    }

    public function getPort(?int $storeId = null): int
    {
        return (int) $this->getValue('general/port', $storeId);
    }

    public function getApiKey(?int $storeId = null): string
    {
        $encrypted = (string) $this->getValue('general/api_key', $storeId);
        return $this->encryptor->decrypt($encrypted);
    }

    public function getSearchOnlyApiKey(?int $storeId = null): string
    {
        return (string) $this->getValue('general/search_only_api_key', $storeId);
    }

    public function getIndexPrefix(?int $storeId = null): string
    {
        return (string) $this->getValue('general/index_prefix', $storeId);
    }

    public function getSearchProtocol(?int $storeId = null): string
    {
        return (string) ($this->getValue('general/search_protocol', $storeId) ?: $this->getProtocol($storeId));
    }

    public function getSearchHost(?int $storeId = null): string
    {
        return (string) ($this->getValue('general/search_host', $storeId) ?: $this->getHost($storeId));
    }

    public function getSearchPort(?int $storeId = null): int
    {
        $port = $this->getValue('general/search_port', $storeId);
        return $port ? (int) $port : $this->getPort($storeId);
    }

    public function isLogEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('general/log_enabled', $storeId);
    }

    public function getCollectionName(string $entityType, string $storeCode, ?int $storeId = null): string
    {
        return sprintf('%s_%s_%s', $this->getIndexPrefix($storeId), $entityType, $storeCode);
    }

    // Indexing
    public function getBatchSize(?int $storeId = null): int
    {
        return (int) $this->getValue('indexing/batch_size', $storeId);
    }

    public function isProductIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/product_enabled', $storeId);
    }

    public function isCategoryIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/category_enabled', $storeId);
    }

    public function isCmsPageIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/cms_page_enabled', $storeId);
    }

    public function isSuggestionIndexingEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/suggestion_enabled', $storeId);
    }

    public function isZeroDowntimeEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/zero_downtime_enabled', $storeId);
    }

    public function isCronEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/cron_enabled', $storeId);
    }

    public function getCronSchedule(?int $storeId = null): string
    {
        return (string) $this->getValue('indexing/cron_schedule', $storeId);
    }

    public function isQueueEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('indexing/queue_enabled', $storeId);
    }

    // Search
    public function isTypoToleranceEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/typo_tolerance_enabled', $storeId);
    }

    public function isHighlightEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('search/highlight_enabled', $storeId);
    }

    // Instant Search
    public function isInstantSearchEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('instant_search/enabled', $storeId);
    }

    public function getProductsPerPage(?int $storeId = null): int
    {
        return (int) $this->getValue('instant_search/products_per_page', $storeId);
    }

    public function isReplaceCategoryPage(?int $storeId = null): bool
    {
        return $this->getFlag('instant_search/replace_category_page', $storeId);
    }

    // Autocomplete
    public function isAutocompleteEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('autocomplete/enabled', $storeId);
    }

    public function getAutocompleteProductCount(?int $storeId = null): int
    {
        return (int) $this->getValue('autocomplete/product_count', $storeId);
    }

    // Merchandising
    public function isCategoryMerchandiserEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('merchandising/category_merchandiser_enabled', $storeId);
    }

    public function isQueryMerchandiserEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('merchandising/query_merchandiser_enabled', $storeId);
    }

    public function isLandingPageEnabled(?int $storeId = null): bool
    {
        return $this->getFlag('merchandising/landing_page_enabled', $storeId);
    }

    private function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PREFIX . '/' . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    private function getFlag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PREFIX . '/' . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }
}
