<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Suggestion;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Api\SelfMaintainedEntityInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionCollectionProvisioner;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionSeeder;

/**
 * Suggestions are the queries Typesense's popular-queries analytics count on the product collection; Magento
 * exports nothing but the one-time seed of a new collection.
 */
class SuggestionEntityIndexer implements EntityIndexerInterface, SelfMaintainedEntityInterface
{
    public function __construct(
        private readonly SuggestionSchemaProviderInterface $schemaProvider,
        private readonly SuggestionCollectionProvisioner $provisioner,
        private readonly SuggestionSeeder $seeder,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
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

    public function provision(int $storeId, string $storeCode): void
    {
        if (!$this->config->isSuggestionIndexingEnabled($storeId)) {
            $this->logger->info("Skipping suggestions for store {$storeCode}: disabled");
            return;
        }

        if (!$this->provisioner->ensure($storeId, $storeCode)) {
            return;
        }

        $stats = $this->seeder->seed($storeId, $storeCode);
        $this->logger->info(
            "Seeded suggestions for {$storeCode}: {$stats['imported']}/{$stats['total']} Magento search terms",
        );
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        return [];
    }

    public function getDocumentId(int $entityId): string
    {
        return (string) $entityId;
    }
}
