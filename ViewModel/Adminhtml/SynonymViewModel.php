<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class SynonymViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSynonyms(string $collection): array
    {
        try {
            $client = $this->clientFactory->create();
            $result = $client->collections[$collection]->synonyms->retrieve();

            return $result['synonyms'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('TypeSense synonyms retrieve for "%s" failed: %s', $collection, $e->getMessage())
            );

            return [];
        }
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
}
