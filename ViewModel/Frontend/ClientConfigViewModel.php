<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class ClientConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getNodeUrl(): string
    {
        return $this->config->getSearchProtocol() . '://' . $this->config->getSearchHost()
            . ':' . $this->config->getSearchPort();
    }

    /**
     * Only the search-only key may reach the browser.
     *
     * @return array{host: string, port: int, protocol: string, apiKey: string}
     */
    public function getClientConfig(): array
    {
        return [
            'host' => $this->config->getSearchHost(),
            'port' => $this->config->getSearchPort(),
            'protocol' => $this->config->getSearchProtocol(),
            'apiKey' => $this->config->getSearchOnlyApiKey(),
        ];
    }

    public function getJsonClientConfig(): string
    {
        return (string) json_encode(
            $this->getClientConfig(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }
}
