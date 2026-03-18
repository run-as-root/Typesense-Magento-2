<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;

readonly class AttributeResolver implements AttributeResolverInterface
{
    public function __construct(
        private AttributeCollectionFactory $attributeCollectionFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraAttributes(Product $product): array
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->addIsSearchableFilter();
        $collection->addFieldToFilter('is_filterable', ['gt' => 0]);

        $coreFields = [
            'entity_id', 'attribute_set_id', 'type_id', 'sku', 'name',
            'description', 'short_description', 'price', 'special_price',
            'visibility', 'status', 'created_at', 'updated_at', 'url_key',
            'image', 'small_image', 'thumbnail',
        ];

        $extra = [];
        foreach ($collection as $attribute) {
            $code = $attribute->getAttributeCode();
            if (in_array($code, $coreFields, true)) {
                continue;
            }

            $value = $product->getData($code);
            if ($value === null || $value === '') {
                continue;
            }

            $extra[$code] = $value;
        }

        return $extra;
    }
}
