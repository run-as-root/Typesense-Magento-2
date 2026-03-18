<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Merchandising;

use Magento\Framework\ObjectManagerInterface;

class LandingPageFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): LandingPage
    {
        return $this->objectManager->create(LandingPage::class, $data);
    }
}
