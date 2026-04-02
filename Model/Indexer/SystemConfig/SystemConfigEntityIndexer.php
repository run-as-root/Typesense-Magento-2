<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\SystemConfig;

use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Api\SystemConfigSchemaProviderInterface;

class SystemConfigEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private readonly SystemConfigDataBuilder $dataBuilder,
        private readonly SystemConfigSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'system_config';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_system_config';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        return $this->dataBuilder->buildDocuments($storeId);
    }
}
