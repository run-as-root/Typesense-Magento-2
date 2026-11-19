<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Boost;

use Magento\Framework\ObjectManagerInterface;

class BoostRuleFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): BoostRule
    {
        return $this->objectManager->create(BoostRule::class, $data);
    }
}
