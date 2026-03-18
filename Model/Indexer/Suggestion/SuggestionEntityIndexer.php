<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

use Magento\Search\Model\Query;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;

readonly class SuggestionEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private SuggestionDataBuilder $dataBuilder,
        private SuggestionSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'suggestion';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_suggestion';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $collection = $this->dataBuilder->getQueryCollection($entityIds, $storeId);

        /** @var Query $query */
        foreach ($collection as $query) {
            yield $this->dataBuilder->build($query, $storeId);
        }
    }
}
