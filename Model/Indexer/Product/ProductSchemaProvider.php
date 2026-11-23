<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\NameDecompounder;
use RunAsRoot\TypeSense\Model\Search\SearchableAttributesProvider;

class ProductSchemaProvider implements ProductSchemaProviderInterface
{
    // Typesense stems the indexed tokens, so filter_by on a stemmed field also matches variants
    // (type_id:=simples finds "simple"). Identifiers and facet values (sku, additional attributes,
    // category paths) must stay exact for facets, virtual categories and boosting rules.
    private const array UNSTEMMED_SEARCHABLE_FIELDS = ['sku'];

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly AttributeResolverInterface $attributeResolver,
    ) {
    }

    public function getCollectionSettings(): array
    {
        return ['enable_nested_fields' => true];
    }

    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        $infixFields = $this->config->isInfixEnabled() ? $this->config->getInfixFields() : [];
        $stemFields = $this->config->isStemEnabled()
            ? array_diff(array_keys(SearchableAttributesProvider::CORE_FIELDS), self::UNSTEMMED_SEARCHABLE_FIELDS)
            : [];

        $fields = [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'product_id', 'type' => 'int32'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'sku', 'type' => 'string'],
            ['name' => 'url', 'type' => 'string'],
            ['name' => 'image_url', 'type' => 'string'],
            ['name' => 'price', 'type' => 'float'],
            ['name' => 'final_price', 'type' => 'float'],
            ['name' => 'regular_price', 'type' => 'float'],
            ['name' => 'description', 'type' => 'string', 'optional' => true],
            ['name' => 'short_description', 'type' => 'string', 'optional' => true],
            ['name' => 'categories', 'type' => 'string[]'],
            ['name' => 'category_ids', 'type' => 'int32[]'],
            ['name' => 'categories.lvl0', 'type' => 'string[]', 'facet' => true],
            ['name' => 'categories.lvl1', 'type' => 'string[]', 'facet' => true],
            ['name' => 'categories.lvl2', 'type' => 'string[]', 'facet' => true],
            ['name' => 'in_stock', 'type' => 'bool', 'facet' => true],
            ['name' => 'type_id', 'type' => 'string', 'facet' => true],
            ['name' => 'visibility', 'type' => 'int32'],
            ['name' => 'created_at', 'type' => 'int64'],
            ['name' => 'updated_at', 'type' => 'int64'],
            ['name' => 'sales_count', 'type' => 'int32'],
            ['name' => 'rating_summary', 'type' => 'int32'],
            ['name' => 'review_count', 'type' => 'int32'],
            // Display-only listing data for grouped products; filtering and sorting use the parent's fields.
            ['name' => 'children', 'type' => 'object[]', 'optional' => true, 'index' => false],
        ];

        $multiselectCodes = $this->attributeResolver->getMultiselectAttributeCodes();
        $additionalAttributes = $this->config->getAdditionalAttributes();
        foreach ($additionalAttributes as $attrCode) {
            $type = in_array($attrCode, $multiselectCodes, true) ? 'string[]' : 'string';
            $fields[] = ['name' => $attrCode, 'type' => $type, 'optional' => true, 'facet' => true];
        }

        // Text representation of categories for embedding
        $fields[] = ['name' => 'categories_text', 'type' => 'string', 'optional' => true];

        if ($this->config->isGermanDecompoundingEnabled()) {
            $fields[] = ['name' => NameDecompounder::FIELD, 'type' => 'string', 'optional' => true];
        }

        $fields = array_map(
            fn(array $field): array => $this->applyTextFlags($field, $infixFields, $stemFields),
            $fields,
        );

        // Auto-embedding for conversational/semantic search
        if ($this->config->isConversationalSearchEnabled()) {
            $fields[] = [
                'name' => 'embedding',
                'type' => 'float[]',
                'embed' => [
                    'from' => $this->config->getEmbeddingFields(),
                    'model_config' => [
                        'model_name' => 'ts/all-MiniLM-L12-v2',
                    ],
                ],
            ];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $field
     * @param string[] $infixFields
     * @param string[] $stemFields
     * @return array<string, mixed>
     */
    private function applyTextFlags(array $field, array $infixFields, array $stemFields): array
    {
        if (!in_array($field['type'], ['string', 'string[]'], true)) {
            return $field;
        }

        if (in_array($field['name'], $stemFields, true)) {
            $field['stem'] = true;
        }

        if (in_array($field['name'], $infixFields, true)) {
            $field['infix'] = true;
        }

        return $field;
    }
}
