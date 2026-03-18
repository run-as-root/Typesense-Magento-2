<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;
use Typesense\Exceptions\ObjectNotFound;

readonly class AliasManager implements AliasManagerInterface
{
    public function __construct(
        private TypeSenseClientFactory $clientFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function upsertAlias(string $aliasName, string $collectionName): array
    {
        $result = $this->clientFactory->create()->aliases->upsert($aliasName, [
            'collection_name' => $collectionName,
        ]);
        $this->logger->info("Upserted alias {$aliasName} → {$collectionName}");

        return $result;
    }

    public function getAlias(string $aliasName): array
    {
        return $this->clientFactory->create()->aliases[$aliasName]->retrieve();
    }

    public function deleteAlias(string $aliasName): array
    {
        $result = $this->clientFactory->create()->aliases[$aliasName]->delete();
        $this->logger->info("Deleted alias: {$aliasName}");

        return $result;
    }

    public function listAliases(): array
    {
        return $this->clientFactory->create()->aliases->retrieve();
    }

    public function aliasExists(string $aliasName): bool
    {
        try {
            $this->getAlias($aliasName);
            return true;
        } catch (ObjectNotFound) {
            return false;
        }
    }
}
