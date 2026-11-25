<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Suggestion;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Suggestion\SuggestionSchemaProviderInterface;

/**
 * A store's suggestion collection and the popular-queries rule that fills it from the searches on the store's
 * product collection.
 */
class SuggestionCollectionProvisioner
{
    public function __construct(
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly CollectionManagerInterface $collectionManager,
        private readonly AliasManagerInterface $aliasManager,
        private readonly SuggestionSchemaProviderInterface $schemaProvider,
        private readonly AnalyticsRuleManager $ruleManager,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Idempotent: an existing collection keeps its documents, the rule is only written when it differs.
     *
     * @return bool Whether the collection was (re)created and is empty
     */
    public function ensure(int $storeId, string $storeCode): bool
    {
        $collectionName = $this->nameResolver->resolve('suggestion', $storeCode, $storeId);
        $created = $this->ensureCollection($collectionName);

        // Typesense matches the source by the collection name a search names, and storefront searches name the
        // product alias: the rule keeps counting across zero-downtime swaps without being re-pointed.
        $this->ruleManager->ensure($this->getRuleName($collectionName), [
            'type' => 'popular_queries',
            'params' => [
                'source' => ['collections' => [$this->nameResolver->resolve('product', $storeCode, $storeId)]],
                'destination' => ['collection' => $collectionName],
                'expand_query' => true,
                'limit' => $this->config->getSuggestionLimit($storeId),
            ],
        ], $storeId);

        return $created;
    }

    public function getRuleName(string $collectionName): string
    {
        return $collectionName . '_popular';
    }

    private function ensureCollection(string $collectionName): bool
    {
        if ($this->aliasManager->aliasExists($collectionName)) {
            $this->dropVersionedExport($collectionName);
        } elseif ($this->collectionManager->collectionExists($collectionName)) {
            if ($this->hasAnalyticsSchema($this->collectionManager->getCollection($collectionName))) {
                return false;
            }
            $this->collectionManager->deleteCollection($collectionName);
            $this->logger->info("Replaced the search term export {$collectionName} by an analytics collection");
        }

        $this->collectionManager->createCollection(
            $collectionName,
            ['name' => $collectionName, 'fields' => $this->schemaProvider->getFields()],
        );

        return true;
    }

    /**
     * Before analytics, suggestions were rebuilt like any entity: versioned collections behind an alias.
     */
    private function dropVersionedExport(string $aliasName): void
    {
        $this->aliasManager->deleteAlias($aliasName);
        $collections = $this->collectionManager->listCollections();
        $pattern = '/^' . preg_quote($aliasName, '/') . '_v\d+$/';

        foreach ($collections as $collection) {
            if (preg_match($pattern, (string) ($collection['name'] ?? '')) === 1) {
                $this->collectionManager->deleteCollection($collection['name']);
            }
        }

        $this->logger->info("Replaced the versioned search term export {$aliasName} by an analytics collection");
    }

    /**
     * @param array<string, mixed> $collection
     */
    private function hasAnalyticsSchema(array $collection): bool
    {
        $types = array_column(is_array($collection['fields'] ?? null) ? $collection['fields'] : [], 'type', 'name');
        $required = $this->schemaProvider->getFields();

        foreach ($required as $field) {
            if (($types[$field['name']] ?? null) !== $field['type']) {
                return false;
            }
        }

        return true;
    }
}
