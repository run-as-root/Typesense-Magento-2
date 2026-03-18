<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\CmsPage;

readonly class CmsPageSchemaProvider implements CmsPageSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        return [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'page_id', 'type' => 'int32'],
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'content', 'type' => 'string'],
            ['name' => 'url_key', 'type' => 'string'],
            ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
        ];
    }
}
