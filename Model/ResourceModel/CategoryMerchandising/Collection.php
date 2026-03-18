<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandising;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising as CategoryMerchandisingResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CategoryMerchandising::class, CategoryMerchandisingResource::class);
    }
}
