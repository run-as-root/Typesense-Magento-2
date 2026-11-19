<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Synonym;

use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class SynonymPusher
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
    ) {
    }

    public function push(SynonymInterface $synonym, string $collectionName): void
    {
        $payload = ['synonyms' => $synonym->getSynonyms()];

        // Typesense treats any synonym carrying a root as one-way, so multi-way ones must omit it.
        if ($synonym->getType() === SynonymInterface::TYPE_ONE_WAY) {
            $payload['root'] = (string) $synonym->getRoot();
        }

        $client = $this->clientFactory->create();
        $client->collections[$collectionName]->synonyms->upsert($synonym->getTypesenseId(), $payload);
    }
}
