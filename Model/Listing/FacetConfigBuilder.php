<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Listing;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProviderInterface;

class FacetConfigBuilder
{
    private const string NUMERIC_TYPE = '/^(int32|int64|float)(\[\])?$/';
    // Document fields the indexer computes; an EAV attribute of the same name says nothing about them.
    private const array COMPUTED_FIELDS = [
        'categories.lvl0', 'categories.lvl1', 'categories.lvl2', 'in_stock', 'is_new', 'type_id',
    ];

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly ProductSchemaProviderInterface $schemaProvider,
        private readonly EavConfig $eavConfig,
    ) {
    }

    /**
     * A facet without a configured type is a dropdown. Price is always the slider and the only one, because
     * the listing state holds a single range (`price`) and never filters price by exact values. The listing
     * core only accepts true/false on checkbox facets, so a checkbox on a non-bool field becomes a dropdown.
     * `numeric` is the Typesense type of a numeric field (int32, int64 or float, also for arrays), else false:
     * the listing core filters those values unquoted and accepts only numbers that fit the type, because
     * Typesense answers backtick-quoted or out-of-range values with a 400 that would fail the whole listing.
     * A facet whose field is missing from the schema or not faceted is dropped for the same reason. On the search
     * page, attributes with "Use in Search Results Layered Navigation" = No are dropped, as Magento and
     * ElasticSuite do.
     *
     * @return list<array{
     *     field: string, label: string, type: string, searchable: bool, boolean: bool, numeric: string|false
     * }>
     */
    public function build(int $storeId, bool $isSearchPage = false): array
    {
        $types = $this->config->getFacetTypes($storeId);
        $schemaTypes = $this->getFacetedSchemaTypes();
        $fields = $this->config->getFacetFilters($storeId);

        $facets = [];
        foreach ($fields as $field) {
            if (!isset($schemaTypes[$field])) {
                continue;
            }
            $attribute = $this->getAttribute($field);
            if ($isSearchPage && $attribute !== null && !$this->isFilterableInSearch($attribute)) {
                continue;
            }
            $schemaType = $schemaTypes[$field];
            $isBoolean = $schemaType === 'bool';
            $type = $field === 'price' ? 'slider' : ($types[$field]['type'] ?? 'dropdown');

            if (($type === 'checkbox' && !$isBoolean) || ($type === 'slider' && $field !== 'price')) {
                $type = 'dropdown';
            }

            $facets[] = [
                'field' => $field,
                'label' => $this->getLabel($field, $attribute, $storeId),
                'type' => $type,
                'searchable' => $type === 'dropdown' && ($types[$field]['searchable'] ?? false),
                'boolean' => $isBoolean,
                'numeric' => preg_match(self::NUMERIC_TYPE, $schemaType, $match) === 1 ? $match[1] : false,
            ];
        }

        return $facets;
    }

    /** @return array<string, string> */
    private function getFacetedSchemaTypes(): array
    {
        $types = [];
        $fields = $this->schemaProvider->getFields();
        foreach ($fields as $field) {
            if (($field['facet'] ?? false) === true) {
                $types[(string) $field['name']] = (string) $field['type'];
            }
        }

        return $types;
    }

    private function getAttribute(string $field): ?Attribute
    {
        if (in_array($field, self::COMPUTED_FIELDS, true)) {
            return null;
        }

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $field);

        return $attribute instanceof Attribute && $attribute->getId() ? $attribute : null;
    }

    // Only an explicit No hides the facet; an attribute loaded without catalog_eav_attribute data keeps it.
    private function isFilterableInSearch(Attribute $attribute): bool
    {
        return (string) $attribute->getData('is_filterable_in_search') !== '0';
    }

    private function getLabel(string $field, ?Attribute $attribute, int $storeId): string
    {
        $coreLabel = match ($field) {
            'categories.lvl0', 'categories.lvl1', 'categories.lvl2' => __('Category'),
            'in_stock' => __('Availability'),
            'is_new' => __('New'),
            'price' => __('Price'),
            'type_id' => __('Product Type'),
            default => null,
        };
        if ($coreLabel !== null) {
            return (string) $coreLabel;
        }

        if ($attribute !== null) {
            return (string) $attribute->getStoreLabel($storeId);
        }

        return ucwords(str_replace(['_', '.'], ' ', $field));
    }
}
