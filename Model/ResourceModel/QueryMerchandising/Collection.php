<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\QueryMerchandising;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use RunAsRoot\TypeSense\Model\Merchandising\QueryMerchandising;
use RunAsRoot\TypeSense\Model\ResourceModel\QueryMerchandising as QueryMerchandisingResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(QueryMerchandising::class, QueryMerchandisingResource::class);
    }
}
