<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\Framework\ObjectManagerInterface;

class CombineFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): Combine
    {
        return $this->objectManager->create(Combine::class, $data);
    }
}
