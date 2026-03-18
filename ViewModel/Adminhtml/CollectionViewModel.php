<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class CollectionViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCollections(): array
    {
        try {
            $client = $this->clientFactory->create();

            return $client->collections->retrieve();
        } catch (\Exception $e) {
            $this->logger->error('TypeSense collections retrieve failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getAliases(): array
    {
        try {
            $client = $this->clientFactory->create();

            return $client->aliases->retrieve();
        } catch (\Exception $e) {
            $this->logger->error('TypeSense aliases retrieve failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Returns a map of collection name => alias name.
     *
     * @return array<string, string>
     */
    public function getAliasMap(): array
    {
        $aliasesResult = $this->getAliases();
        $aliasMap = [];

        foreach ($aliasesResult['aliases'] ?? [] as $alias) {
            $collectionName = (string) ($alias['collection_name'] ?? '');
            $aliasName = (string) ($alias['name'] ?? '');

            if ($collectionName !== '' && $aliasName !== '') {
                $aliasMap[$collectionName] = $aliasName;
            }
        }

        return $aliasMap;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCollectionDetails(string $name): array
    {
        try {
            $client = $this->clientFactory->create();

            return $client->collections[$name]->retrieve();
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('TypeSense collection "%s" retrieve failed: %s', $name, $e->getMessage())
            );

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocuments(string $name, int $perPage = 10): array
    {
        try {
            $client = $this->clientFactory->create();

            $schema = $client->collections[$name]->retrieve();
            $queryBy = $this->resolveQueryByField($schema['fields'] ?? []);

            $searchParams = [
                'q'        => '*',
                'per_page' => $perPage,
            ];

            if ($queryBy !== '') {
                $searchParams['query_by'] = $queryBy;
            }

            return $client->collections[$name]->documents->search($searchParams);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('TypeSense documents retrieve for "%s" failed: %s', $name, $e->getMessage())
            );

            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function resolveQueryByField(array $fields): string
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'string' && ($field['name'] ?? '') !== 'id') {
                return (string) $field['name'];
            }
        }

        return '';
    }
}
