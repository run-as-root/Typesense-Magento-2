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

    // Every field getFields() can declare, including the feature-dependent ones, so an additional
    // attribute of the same name never duplicates or overwrites a core field.
    public const array RESERVED_FIELDS = [
        'id', 'product_id', 'name', 'sku', 'url', 'image_url', 'price', 'final_price', 'regular_price',
        'description', 'short_description', 'categories', 'category_ids', 'categories.lvl0', 'categories.lvl1',
        'categories.lvl2', 'in_stock', 'is_new', 'type_id', 'visibility', 'created_at', 'updated_at',
        'sales_count', 'rating_summary', 'review_count', 'children', 'children_text', 'categories_text',
        NameDecompounder::FIELD, 'embedding',
    ];

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
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
            // Faceted for the listing price slider's min/max stats.
            ['name' => 'price', 'type' => 'float', 'facet' => true],
            ['name' => 'final_price', 'type' => 'float'],
            ['name' => 'regular_price', 'type' => 'float'],
            ['name' => 'description', 'type' => 'string', 'optional' => true],
            ['name' => 'short_description', 'type' => 'string', 'optional' => true],
            ['name' => 'categories', 'type' => 'string[]'],
            ['name' => 'category_ids', 'type' => 'int32[]', 'facet' => true],
            ['name' => 'categories.lvl0', 'type' => 'string[]', 'facet' => true],
            ['name' => 'categories.lvl1', 'type' => 'string[]', 'facet' => true],
            ['name' => 'categories.lvl2', 'type' => 'string[]', 'facet' => true],
            // Typesense sorts bools by default; explicit because listings rely on in_stock:desc (out of stock last).
            ['name' => 'in_stock', 'type' => 'bool', 'facet' => true, 'sort' => true],
            ['name' => 'is_new', 'type' => 'bool', 'facet' => true, 'optional' => true],
            ['name' => 'type_id', 'type' => 'string', 'facet' => true],
            ['name' => 'visibility', 'type' => 'int32'],
            ['name' => 'created_at', 'type' => 'int64'],
            ['name' => 'updated_at', 'type' => 'int64'],
            ['name' => 'sales_count', 'type' => 'int32'],
            ['name' => 'rating_summary', 'type' => 'int32'],
            ['name' => 'review_count', 'type' => 'int32'],
            // Display-only listing data for grouped products; the parent's attribute fields and children_text
            // carry the children's values for filtering and search.
            ['name' => 'children', 'type' => 'object[]', 'optional' => true, 'index' => false],
            ['name' => 'children_text', 'type' => 'string[]', 'optional' => true],
        ];

        // Config imported from files bypasses the admin source that hides core attributes, and Typesense
        // rejects a schema with duplicate field names.
        $additionalAttributes = array_diff($this->config->getAdditionalAttributes(), self::RESERVED_FIELDS);
        foreach ($additionalAttributes as $attrCode) {
            // An array even for a single select: a composite parent holds the values of all its children.
            $fields[] = ['name' => $attrCode, 'type' => 'string[]', 'optional' => true, 'facet' => true];
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
