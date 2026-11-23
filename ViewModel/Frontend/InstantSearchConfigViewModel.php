<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * Listing settings moved to ListingConfigViewModel; this only gates the search page extras (title, term logging).
 */
class InstantSearchConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isInstantSearchEnabled();
    }
}
