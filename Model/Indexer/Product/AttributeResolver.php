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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraAttributes(Product $product): array
    {
        $collection = $this->getAttributeCollection();

        $extra = [];
        foreach ($collection as $attribute) {
            $code = $attribute->getAttributeCode();
            if (in_array($code, self::CORE_FIELDS, true)) {
                continue;
            }

            $frontendInput = $attribute->getFrontendInput();

            // For select/multiselect/boolean, resolve option labels instead of IDs
            if (in_array($frontendInput, ['select', 'multiselect', 'boolean'], true)) {
                $text = $product->getAttributeText($code);
                if ($text === false || $text === '' || $text === null || $text === []) {
                    continue;
                }
                // Multiselect values are facet-filtered one by one, so they stay an array of labels.
                // getAttributeText returns a plain string when only one option is selected.
                $extra[$code] = $frontendInput === 'multiselect'
                    ? array_values(array_map('strval', (array) $text))
                    : (string) $text;
            } else {
                $value = $product->getData($code);
                if ($value === null || $value === '') {
                    continue;
                }
                $extra[$code] = $value;
            }
        }

        return $extra;
    }

    public function getMultiselectAttributeCodes(): array
    {
        $codes = [];
        foreach ($this->getAttributeCollection() as $attribute) {
            if ($attribute->getFrontendInput() === 'multiselect') {
                $codes[] = $attribute->getAttributeCode();
            }
        }

        return $codes;
    }

    private function getAttributeCollection(): AttributeCollection
    {
        if ($this->cachedCollection === null) {
            $additionalAttributes = $this->config->getAdditionalAttributes();
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
