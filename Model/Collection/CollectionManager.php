<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;
use Typesense\Exceptions\ObjectNotFound;

class CollectionManager implements CollectionManagerInterface
{
    public function __construct(
        private readonly TypeSenseClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createCollection(string $name, array $schema): array
    {
        $schema['name'] = $name;
        $result = $this->clientFactory->create()->collections->create($schema);
        $this->logger->info("Created Typesense collection: {$name}");

        return $result;
    }

    public function deleteCollection(string $name): array
    {
        $result = $this->clientFactory->create()->collections[$name]->delete();
        $this->logger->info("Deleted Typesense collection: {$name}");

        return $result;
    }

    public function getCollection(string $name): array
    {
        return $this->clientFactory->create()->collections[$name]->retrieve();
    }

    public function listCollections(): array
    {
        return $this->clientFactory->create()->collections->retrieve();
    }

    public function collectionExists(string $name): bool
    {
        try {
            $this->getCollection($name);
            return true;
        } catch (ObjectNotFound) {
            return false;
        }
    }
}
