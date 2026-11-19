<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\BoostRule;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use RunAsRoot\TypeSense\Model\Boost\BoostRule;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule as BoostRuleResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(BoostRule::class, BoostRuleResource::class);
    }
}
