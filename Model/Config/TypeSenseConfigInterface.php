<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config;

interface TypeSenseConfigInterface
{
    public function isEnabled(?int $storeId = null): bool;

    public function getProtocol(?int $storeId = null): string;

    public function getHost(?int $storeId = null): string;

    public function getPort(?int $storeId = null): int;

    public function getApiKey(?int $storeId = null): string;

    public function getSearchOnlyApiKey(?int $storeId = null): string;

    public function getIndexPrefix(?int $storeId = null): string;

    public function getSearchProtocol(?int $storeId = null): string;

    public function getSearchHost(?int $storeId = null): string;

    public function getSearchPort(?int $storeId = null): int;

    public function isLogEnabled(?int $storeId = null): bool;

    public function getCollectionName(string $entityType, string $storeCode, ?int $storeId = null): string;

    public function getBatchSize(?int $storeId = null): int;

    public function isProductIndexingEnabled(?int $storeId = null): bool;

    public function isCategoryIndexingEnabled(?int $storeId = null): bool;

    public function isCmsPageIndexingEnabled(?int $storeId = null): bool;

    public function isSuggestionIndexingEnabled(?int $storeId = null): bool;

    public function isZeroDowntimeEnabled(?int $storeId = null): bool;

    public function isCronEnabled(?int $storeId = null): bool;

    public function getCronSchedule(?int $storeId = null): string;

    public function isQueueEnabled(?int $storeId = null): bool;

    public function isTypoToleranceEnabled(?int $storeId = null): bool;

    public function isHighlightEnabled(?int $storeId = null): bool;

    public function isInstantSearchEnabled(?int $storeId = null): bool;

    public function getProductsPerPage(?int $storeId = null): int;

    public function isReplaceCategoryPage(?int $storeId = null): bool;

    public function isAutocompleteEnabled(?int $storeId = null): bool;

    public function getAutocompleteProductCount(?int $storeId = null): int;

    public function isCategoryMerchandiserEnabled(?int $storeId = null): bool;

    public function isQueryMerchandiserEnabled(?int $storeId = null): bool;
}
