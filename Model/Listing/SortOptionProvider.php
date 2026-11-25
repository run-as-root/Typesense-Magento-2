<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Listing;

use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\SortableAttributeProvider;

/**
 * The sort options of the storefront listing, in the configured order. `code` is the Magento sort attribute code
 * the option stands for, matched against a category's available_sort_by and default_sort_by.
 */
class SortOptionProvider
{
    /** @var array<string, array{label: string, value: string, code: string}> option key => option */
    public const array STATIC_OPTIONS = [
        'relevance'     => ['label' => 'Relevance', 'value' => '', 'code' => 'position'],
        'price_asc'     => ['label' => 'Price: Low to High', 'value' => 'price:asc', 'code' => 'price'],
        'price_desc'    => ['label' => 'Price: High to Low', 'value' => 'price:desc', 'code' => 'price'],
        'newest'        => ['label' => 'Newest', 'value' => 'created_at:desc', 'code' => 'created_at'],
        'name_asc'      => ['label' => 'Name: A–Z', 'value' => 'name:asc', 'code' => 'name'],
        'name_desc'     => ['label' => 'Name: Z–A', 'value' => 'name:desc', 'code' => 'name'],
        'best_selling'  => ['label' => 'Best Selling', 'value' => 'sales_count:desc', 'code' => 'sales_count'],
        'top_rated'     => ['label' => 'Top Rated', 'value' => 'rating_summary:desc', 'code' => 'rating_summary'],
        'most_reviewed' => ['label' => 'Most Reviewed', 'value' => 'review_count:desc', 'code' => 'review_count'],
    ];

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly SortableAttributeProvider $sortableAttributes,
    ) {
    }

    /**
     * Labels of the fixed options are untranslated; attribute options carry the attribute's store label.
     *
     * @return list<array{label: string, value: string, code: string}>
     */
    public function getOptions(int $storeId): array
    {
        $keys = $this->config->getSortOptionKeys($storeId);
        $sortable = $this->sortableAttributes->getSortableAttributes($storeId);
        $directions = $this->getAttributeDirections($keys, $sortable);

        $options = [];
        foreach ($keys as $key) {
            if (isset(self::STATIC_OPTIONS[$key])) {
                $options[] = self::STATIC_OPTIONS[$key];
                continue;
            }

            [$code, $direction] = SortableAttributeProvider::parseOptionKey($key) ?? [null, null];
            if ($code === null || !isset($sortable[$code])) {
                continue;
            }

            $options[] = [
                'label' => $this->getAttributeLabel($code, $direction, count($directions[$code]) > 1, $storeId),
                'value' => $this->getAttributeSortBy($code, $direction, $sortable[$code]),
                'code' => $code,
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $keys
     * @param array<string, string> $sortable
     * @return array<string, list<string>> attribute code => configured directions
     */
    private function getAttributeDirections(array $keys, array $sortable): array
    {
        $directions = [];
        foreach ($keys as $key) {
            [$code, $direction] = SortableAttributeProvider::parseOptionKey($key) ?? [null, null];
            if ($code !== null && isset($sortable[$code])) {
                $directions[$code][] = $direction;
            }
        }

        return $directions;
    }

    private function getAttributeLabel(string $code, string $direction, bool $bothDirections, int $storeId): string
    {
        $label = $this->sortableAttributes->getStoreLabel($code, $storeId) ?? $code;
        if (!$bothDirections) {
            return $label;
        }

        return (string) ($direction === 'asc' ? __('%1: Low to High', $label) : __('%1: High to Low', $label));
    }

    // Typesense puts documents without a numeric value last either way, but a missing string first when descending.
    private function getAttributeSortBy(string $code, string $direction, string $type): string
    {
        $field = SortableAttributeProvider::getFieldName($code);

        return $type === SortableAttributeProvider::TYPE_STRING
            ? "{$field}(missing_values: last):{$direction}"
            : "{$field}:{$direction}";
    }
}
