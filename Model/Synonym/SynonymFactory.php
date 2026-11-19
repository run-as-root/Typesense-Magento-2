<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Synonym;

use Magento\Framework\ObjectManagerInterface;

class SynonymFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): Synonym
    {
        return $this->objectManager->create(Synonym::class, $data);
    }
}
