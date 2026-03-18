<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Category;

class CategorySchemaProvider implements CategorySchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        return [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'category_id', 'type' => 'int32'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'url', 'type' => 'string'],
            ['name' => 'path', 'type' => 'string'],
            ['name' => 'level', 'type' => 'int32'],
            ['name' => 'description', 'type' => 'string', 'optional' => true],
            ['name' => 'image_url', 'type' => 'string', 'optional' => true],
            ['name' => 'product_count', 'type' => 'int32'],
            ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
        ];
    }
}
