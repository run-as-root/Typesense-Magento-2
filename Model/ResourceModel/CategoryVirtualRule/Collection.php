<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRule;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CategoryVirtualRule::class, CategoryVirtualRuleResource::class);
    }
}
