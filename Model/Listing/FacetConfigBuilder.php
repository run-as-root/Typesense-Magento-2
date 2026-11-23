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
     *
     * @return list<array{field: string, label: string, type: string, searchable: bool, boolean: bool}>
     */
    public function build(int $storeId): array
    {
        $types = $this->config->getFacetTypes($storeId);
        $schemaTypes = $this->getSchemaTypes();
        $fields = $this->config->getFacetFilters($storeId);

        $facets = [];
        foreach ($fields as $field) {
            $isBoolean = ($schemaTypes[$field] ?? 'string') === 'bool';
            $type = $field === 'price' ? 'slider' : ($types[$field]['type'] ?? 'dropdown');

            if (($type === 'checkbox' && !$isBoolean) || ($type === 'slider' && $field !== 'price')) {
                $type = 'dropdown';
            }

            $facets[] = [
                'field' => $field,
                'label' => $this->getLabel($field, $storeId),
                'type' => $type,
                'searchable' => $type === 'dropdown' && ($types[$field]['searchable'] ?? false),
                'boolean' => $isBoolean,
            ];
        }

        return $facets;
    }

    /** @return array<string, string> */
    private function getSchemaTypes(): array
    {
        $types = [];
        $fields = $this->schemaProvider->getFields();
        foreach ($fields as $field) {
            $types[(string) $field['name']] = (string) $field['type'];
        }

        return $types;
    }

    private function getLabel(string $field, int $storeId): string
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

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $field);
        if ($attribute instanceof Attribute && $attribute->getId()) {
            return (string) $attribute->getStoreLabel($storeId);
        }

        return ucwords(str_replace(['_', '.'], ' ', $field));
    }
}
