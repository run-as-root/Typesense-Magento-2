<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\ObjectManagerInterface;

class CategoryVirtualRuleFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): CategoryVirtualRule
    {
        return $this->objectManager->create(CategoryVirtualRule::class, $data);
    }
}
