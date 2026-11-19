<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\Synonym;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use RunAsRoot\TypeSense\Model\ResourceModel\Synonym as SynonymResource;
use RunAsRoot\TypeSense\Model\Synonym\Synonym;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Synonym::class, SynonymResource::class);
    }
}
