<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Store;

use RunAsRoot\TypeSense\Api\StoreSchemaProviderInterface;

class StoreSchemaProvider implements StoreSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        return [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'store_id', 'type' => 'int32'],
            ['name' => 'store_code', 'type' => 'string'],
            ['name' => 'store_name', 'type' => 'string'],
            ['name' => 'website_id', 'type' => 'int32'],
            ['name' => 'website_code', 'type' => 'string'],
            ['name' => 'website_name', 'type' => 'string'],
            ['name' => 'group_id', 'type' => 'int32'],
            ['name' => 'group_name', 'type' => 'string'],
            ['name' => 'root_category_id', 'type' => 'int32'],
            ['name' => 'base_url', 'type' => 'string'],
            ['name' => 'base_currency', 'type' => 'string'],
            ['name' => 'default_locale', 'type' => 'string'],
            ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
        ];
    }
}
