<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class AssistantViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled()
            && $this->config->isAdminAssistantEnabled();
    }
}
