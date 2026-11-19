<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class SynonymViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly SynonymRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{id: string, synonyms: string[], root?: string}>
     */
    public function getSynonyms(string $collection): array
    {
        $aliasName = (string) preg_replace('/_v\d+$/', '', $collection);

        try {
            $synonyms = $this->repository->getByCollection($aliasName);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('TypeSense synonyms retrieve for "%s" failed: %s', $aliasName, $e->getMessage())
            );

            return [];
        }

        return array_map(static function (SynonymInterface $synonym): array {
            $row = ['id' => $synonym->getTypesenseId(), 'synonyms' => $synonym->getSynonyms()];
            if ($synonym->getType() === SynonymInterface::TYPE_ONE_WAY) {
                $row['root'] = (string) $synonym->getRoot();
            }

            return $row;
        }, $synonyms);
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
