<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\SystemConfig;

use RunAsRoot\TypeSense\Api\SystemConfigSchemaProviderInterface;

class SystemConfigSchemaProvider implements SystemConfigSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        return [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'path', 'type' => 'string'],
            ['name' => 'scope', 'type' => 'string', 'facet' => true],
            ['name' => 'scope_id', 'type' => 'int32'],
            ['name' => 'value', 'type' => 'string'],
            ['name' => 'section', 'type' => 'string', 'facet' => true],
            ['name' => 'group_field', 'type' => 'string'],
            ['name' => 'field', 'type' => 'string'],
            ['name' => 'label', 'type' => 'string'],
        ];
    }
}
