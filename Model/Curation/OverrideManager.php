<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Curation;

use RunAsRoot\TypeSense\Api\OverrideManagerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class OverrideManager implements OverrideManagerInterface
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
    ) {
    }

    public function createOverride(string $collection, string $overrideId, array $rule): array
    {
        $client = $this->clientFactory->create();
        return $client->collections[$collection]->overrides->upsert($overrideId, $rule);
    }

    public function deleteOverride(string $collection, string $overrideId): array
    {
        $client = $this->clientFactory->create();
        return $client->collections[$collection]->overrides[$overrideId]->delete();
    }

    public function listOverrides(string $collection): array
    {
        $client = $this->clientFactory->create();
        return $client->collections[$collection]->overrides->retrieve();
    }
}
