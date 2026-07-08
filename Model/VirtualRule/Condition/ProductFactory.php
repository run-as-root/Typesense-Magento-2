<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\Framework\ObjectManagerInterface;

class ProductFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): Product
    {
        return $this->objectManager->create(Product::class, $data);
    }
}
