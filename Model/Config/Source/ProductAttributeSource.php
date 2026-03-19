<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class ProductAttributeSource implements OptionSourceInterface
{
    private const array EXCLUDED_ATTRIBUTES = [
        'entity_id',
        'attribute_set_id',
        'type_id',
        'sku',
        'name',
        'description',
        'short_description',
        'price',
        'special_price',
        'visibility',
        'status',
        'created_at',
        'updated_at',
        'url_key',
        'image',
        'small_image',
        'thumbnail',
        'media_gallery',
        'gallery',
        'has_options',
        'required_options',
        'old_id',
        'tier_price',
        'category_ids',
        'quantity_and_stock_status',
    ];

    /**
     * Human-readable labels for Magento frontend input types.
     */
    private const array INPUT_TYPE_LABELS = [
        'text'        => 'text',
        'textarea'    => 'textarea',
        'select'      => 'select',
        'multiselect' => 'multiselect',
        'boolean'     => 'boolean',
        'price'       => 'price',
        'date'        => 'date',
        'weight'      => 'weight',
        'media_image' => 'image',
        'gallery'     => 'gallery',
    ];

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
    ) {
    }

    public function toOptionArray(): array
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->addVisibleFilter();
        $collection->setOrder('frontend_label', 'ASC');

        // Group attributes by their frontend input type so admins can quickly
        // identify which attributes make sense to index or use as facets.
        $groups = [];

        foreach ($collection as $attribute) {
            $code = $attribute->getAttributeCode();

            if (in_array($code, self::EXCLUDED_ATTRIBUTES, true)) {
                continue;
            }

            $label     = $attribute->getFrontendLabel() ?: $code;
            $inputType = $attribute->getFrontendInput() ?? 'text';
            $typeLabel = self::INPUT_TYPE_LABELS[$inputType] ?? $inputType;
            $groupKey  = $this->resolveGroupKey($inputType);

            $groups[$groupKey][] = [
                'value' => $code,
                'label' => $label . ' (' . $code . ') — ' . $typeLabel,
            ];
        }

        ksort($groups);

        $options = [];

        foreach ($groups as $groupLabel => $items) {
            usort($items, static fn(array $a, array $b) => strcmp((string) $a['label'], (string) $b['label']));

            $options[] = [
                'label' => $groupLabel,
                'value' => $items,
            ];
        }

        return $options;
    }

    /**
     * Map a frontend input type to a human-readable optgroup label.
     */
    private function resolveGroupKey(string $inputType): string
    {
        return match ($inputType) {
            'select', 'multiselect' => 'Filterable (Select / Multiselect)',
            'boolean'               => 'Filterable (Yes/No)',
            'price', 'weight'       => 'Numeric (Price / Weight)',
            'date'                  => 'Date',
            'text', 'textarea'      => 'Text',
            default                 => 'Other',
        };
    }
}
