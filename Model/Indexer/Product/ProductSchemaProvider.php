<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

readonly class ProductSchemaProvider implements ProductSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        return [
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
        ];
    }
}
