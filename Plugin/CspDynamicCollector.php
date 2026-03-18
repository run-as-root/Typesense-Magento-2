<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Plugin;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class CspDynamicCollector implements PolicyCollectorInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /**
     * @param FetchPolicy[] $defaultPolicies
     * @return FetchPolicy[]
     */
    public function collect(array $defaultPolicies = []): array
    {
        if (!$this->config->isEnabled()) {
            return $defaultPolicies;
        }

        $host = $this->config->getHost();
        $port = $this->config->getPort();
        $protocol = $this->config->getProtocol();

        $hostValue = $protocol . '://' . $host;
        if ($port !== 443 && $port !== 80) {
            $hostValue .= ':' . $port;
        }

        $defaultPolicies[] = new FetchPolicy(
            'connect-src',
            false,
            [$hostValue],
        );

        return $defaultPolicies;
    }
}
