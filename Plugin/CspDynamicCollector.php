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

        // The browser talks to the search node; the indexing node stays allowed for setups that use one host.
        $sources = array_unique([
            $this->toSource($this->config->getProtocol(), $this->config->getHost(), $this->config->getPort()),
            $this->toSource(
                $this->config->getSearchProtocol(),
                $this->config->getSearchHost(),
                $this->config->getSearchPort(),
            ),
        ]);

        $defaultPolicies[] = new FetchPolicy('connect-src', false, array_values($sources));

        return $defaultPolicies;
    }

    private function toSource(string $protocol, string $host, int $port): string
    {
        $source = $protocol . '://' . $host;
        if ($port !== 443 && $port !== 80) {
            $source .= ':' . $port;
        }

        return $source;
    }
}
