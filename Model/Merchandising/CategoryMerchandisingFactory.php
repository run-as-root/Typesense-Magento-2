<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Merchandising;

use Magento\Framework\ObjectManagerInterface;

class CategoryMerchandisingFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): CategoryMerchandising
    {
        return $this->objectManager->create(CategoryMerchandising::class, $data);
    }
}
