<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection as AttributeCollection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class AttributeResolver implements AttributeResolverInterface
{
    private const array CORE_FIELDS = [
        'entity_id', 'attribute_set_id', 'type_id', 'sku', 'name',
        'description', 'short_description', 'price', 'special_price',
        'visibility', 'status', 'created_at', 'updated_at', 'url_key',
        'image', 'small_image', 'thumbnail',
    ];

    private ?AttributeCollection $cachedCollection = null;

    public function __construct(
        private readonly AttributeCollectionFactory $attributeCollectionFactory,
        private readonly TypeSenseConfigInterface $config,
        private readonly SortableAttributeProvider $sortableAttributes,
    ) {
    }

    /**
     * @return array<string, list<string>>
     */
    public function getExtraAttributes(Product $product): array
    {
        $collection = $this->getAttributeCollection();

        $extra = [];
        foreach ($collection as $attribute) {
            $code = $attribute->getAttributeCode();
            if (in_array($code, self::CORE_FIELDS, true)
                || in_array($code, ProductSchemaProvider::RESERVED_FIELDS, true)
            ) {
                continue;
            }

            // Option attributes are indexed by label; getAttributeText returns a plain string for one option.
            $value = in_array($attribute->getFrontendInput(), ['select', 'multiselect', 'boolean'], true)
                ? $product->getAttributeText($code)
                : $product->getData($code);
            if ($value === false || $value === null || $value === '' || $value === []) {
                continue;
            }

            $extra[$code] = array_values(array_unique(array_map('strval', (array) $value)));
        }

        return $extra;
    }

    private function getAttributeCollection(): AttributeCollection
    {
        if ($this->cachedCollection === null) {
            $sortableCodes = array_keys($this->sortableAttributes->getSortableAttributes());
            $additionalAttributes = array_values(array_unique([...$this->config->getAdditionalAttributes(), ...$sortableCodes]));
            $collection = $this->attributeCollectionFactory->create();

            if ($additionalAttributes !== []) {
                $collection->addFieldToFilter('attribute_code', ['in' => $additionalAttributes]);
            } else {
                $collection->addFieldToFilter('attribute_code', ['in' => ['__none__']]);
            }

            $this->cachedCollection = $collection;
        }

        return $this->cachedCollection;
    }
}
