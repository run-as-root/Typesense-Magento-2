<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class DashboardViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthStatus(): array
    {
        try {
            $client = $this->clientFactory->create();

            return $client->health->retrieve();
        } catch (\Exception $e) {
            $this->logger->error('TypeSense health check failed: ' . $e->getMessage());

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

    public function isConnected(): bool
    {
        try {
            $client = $this->clientFactory->create();
            $result = $client->health->retrieve();

            return ($result['ok'] ?? false) === true;
        } catch (\Exception $e) {
            $this->logger->error('TypeSense connection check failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return array{host: string, port: int, protocol: string}
     */
    public function getConnectionInfo(): array
    {
        return [
            'host'     => $this->config->getHost(),
            'port'     => $this->config->getPort(),
            'protocol' => $this->config->getProtocol(),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function hasApiKey(): bool
    {
        return $this->config->getApiKey() !== '';
    }

    public function hasSearchOnlyApiKey(): bool
    {
        return $this->config->getSearchOnlyApiKey() !== '';
    }

    public function isAutocompleteEnabled(): bool
    {
        return $this->config->isAutocompleteEnabled();
    }

    public function getTotalDocuments(): int
    {
        $total = 0;
        foreach ($this->getCollections() as $collection) {
            $total += (int) ($collection['num_documents'] ?? 0);
        }

        return $total;
    }
}
