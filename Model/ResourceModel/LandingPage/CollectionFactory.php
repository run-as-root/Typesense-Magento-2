<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\LandingPage;

use Magento\Framework\ObjectManagerInterface;

class CollectionFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): Collection
    {
        return $this->objectManager->create(Collection::class, $data);
    }
}
