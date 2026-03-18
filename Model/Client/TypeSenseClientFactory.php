<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Client;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use Typesense\Client;

class TypeSenseClientFactory implements TypeSenseClientFactoryInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(?int $storeId = null): Client
    {
        return new Client([
            'api_key' => $this->config->getApiKey($storeId),
            'nodes' => [
                [
                    'host' => $this->config->getHost($storeId),
                    'port' => (string) $this->config->getPort($storeId),
                    'protocol' => $this->config->getProtocol($storeId),
                ],
            ],
            'logger' => $this->logger,
        ]);
    }
}
