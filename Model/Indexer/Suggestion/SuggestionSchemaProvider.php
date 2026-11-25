<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

class SuggestionSchemaProvider implements SuggestionSchemaProviderInterface
{
    /**
     * Typesense's popular-queries analytics require `q` and `count`. `is_valid` is set by the pruner; it must be
     * optional, since Typesense creates documents for new queries without it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFields(): array
    {
        return [
            ['name' => 'q', 'type' => 'string'],
            ['name' => 'count', 'type' => 'int32'],
            ['name' => 'is_valid', 'type' => 'bool', 'optional' => true],
        ];
    }
}
