<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

class SuggestionSchemaProvider implements SuggestionSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        return [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'query', 'type' => 'string'],
            ['name' => 'num_results', 'type' => 'int32'],
            ['name' => 'popularity', 'type' => 'int32'],
        ];
    }
}
