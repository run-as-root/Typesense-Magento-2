<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Curation;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use Typesense\Exceptions\ObjectNotFound;

class OverrideManager implements OverrideManagerInterface
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createOverride(string $collection, string $overrideId, array $rule): array
    {
        $client = $this->clientFactory->create();
        $result = $client->collections[$collection]->overrides->upsert($overrideId, $rule);
        $this->logger->info("Upserted override {$overrideId} on collection {$collection}");
        return $result;
    }

    public function deleteOverride(string $collection, string $overrideId): array
    {
        $client = $this->clientFactory->create();

        try {
            $result = $client->collections[$collection]->overrides[$overrideId]->delete();
            $this->logger->info("Deleted override {$overrideId} from collection {$collection}");
            return $result;
        } catch (ObjectNotFound) {
            $this->logger->info("Override {$overrideId} not found on collection {$collection} — nothing to delete");
            return [];
        }
    }

    public function listOverrides(string $collection): array
    {
        $client = $this->clientFactory->create();
        return $client->collections[$collection]->overrides->retrieve();
    }
}
