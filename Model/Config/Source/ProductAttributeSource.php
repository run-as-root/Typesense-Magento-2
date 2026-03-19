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

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
    ) {
    }

    public function toOptionArray(): array
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->addVisibleFilter();
        $collection->setOrder('frontend_label', 'ASC');

        $options = [];

        foreach ($collection as $attribute) {
            $code = $attribute->getAttributeCode();

            if (in_array($code, self::EXCLUDED_ATTRIBUTES, true)) {
                continue;
            }

            $label = $attribute->getFrontendLabel() ?: $code;
            $options[] = [
                'value' => $code,
                'label' => $label . ' (' . $code . ')',
            ];
        }

        return $options;
    }
}
