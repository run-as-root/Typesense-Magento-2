<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProvider;
use RunAsRoot\TypeSense\Model\Indexer\Product\SortableAttributeProvider;
use RunAsRoot\TypeSense\Model\Listing\SortOptionProvider;

class SortOptionSource implements OptionSourceInterface
{
    // Already covered by fixed options (position is relevance).
    private const array FIXED_SORT_ATTRIBUTES = ['position', 'name', 'price'];

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach (SortOptionProvider::STATIC_OPTIONS as $key => $option) {
            $options[] = ['value' => $key, 'label' => __($option['label'])];
        }

        // Magento's "Used for Sorting in Product Listing" attributes, like a category's available_sort_by.
        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter('used_for_sort_by', ['eq' => 1]);
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if (in_array($code, self::FIXED_SORT_ATTRIBUTES, true)
                || in_array($code, ProductSchemaProvider::RESERVED_FIELDS, true)
            ) {
                continue;
            }

            $label = (string) $attribute->getFrontendLabel();
            $prefix = SortableAttributeProvider::OPTION_PREFIX . $code;
            $options[] = ['value' => $prefix . ':asc', 'label' => __('%1 (ascending)', $label)];
            $options[] = ['value' => $prefix . ':desc', 'label' => __('%1 (descending)', $label)];
        }

        return $options;
    }
}
