<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

class SuggestionSchemaProvider implements SuggestionSchemaProviderInterface
{
    /**
     * Typesense's popular-queries analytics require these two fields in the destination collection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFields(): array
    {
        return [
            ['name' => 'q', 'type' => 'string'],
            ['name' => 'count', 'type' => 'int32'],
        ];
    }
}
