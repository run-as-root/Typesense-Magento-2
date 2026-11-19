<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\NameDecompounder;

class ProductSchemaProvider implements ProductSchemaProviderInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        $infixFields = $this->config->isInfixEnabled() ? $this->config->getInfixFields() : [];
        $stem = $this->config->isStemEnabled();

        $fields = [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'product_id', 'type' => 'int32'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'sku', 'type' => 'string'],
            ['name' => 'url', 'type' => 'string'],
            ['name' => 'image_url', 'type' => 'string'],
            ['name' => 'price', 'type' => 'float'],
            ['name' => 'special_price', 'type' => 'float', 'optional' => true],
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
        ];

        foreach ($this->config->getAdditionalAttributes() as $attrCode) {
            $fields[] = ['name' => $attrCode, 'type' => 'string', 'optional' => true, 'facet' => true];
        }

        // Text representation of categories for embedding
        $fields[] = ['name' => 'categories_text', 'type' => 'string', 'optional' => true];

        if ($this->config->isGermanDecompoundingEnabled()) {
            $fields[] = ['name' => NameDecompounder::FIELD, 'type' => 'string', 'optional' => true];
        }

        $fields = array_map(fn(array $field): array => $this->applyTextFlags($field, $infixFields, $stem), $fields);

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
     * @return array<string, mixed>
     */
    private function applyTextFlags(array $field, array $infixFields, bool $stem): array
    {
        if (!in_array($field['type'], ['string', 'string[]'], true)) {
            return $field;
        }

        // The document id is an opaque key, not text to stem.
        if ($stem && $field['name'] !== 'id') {
            $field['stem'] = true;
        }

        if (in_array($field['name'], $infixFields, true)) {
            $field['infix'] = true;
        }

        return $field;
    }
}
