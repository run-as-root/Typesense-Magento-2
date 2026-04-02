<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use OpenAI;
use OpenAI\Contracts\ClientContract;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class OpenAiClientFactory
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function create(): ClientContract
    {
        $apiKey = $this->config->getOpenAiApiKey();

        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        return OpenAI::client($apiKey);
    }
}
