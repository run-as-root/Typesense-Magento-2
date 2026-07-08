<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\Framework\ObjectManagerInterface;

class ConditionsRuleFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): ConditionsRule
    {
        return $this->objectManager->create(ConditionsRule::class, $data);
    }
}
