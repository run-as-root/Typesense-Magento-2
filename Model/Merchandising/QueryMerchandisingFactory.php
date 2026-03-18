<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Merchandising;

use Magento\Framework\ObjectManagerInterface;

class QueryMerchandisingFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): QueryMerchandising
    {
        return $this->objectManager->create(QueryMerchandising::class, $data);
    }
}
