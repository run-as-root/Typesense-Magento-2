<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CategoryVirtualRule extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('run_as_root_typesense_category_virtual_rule', 'id');
    }
}
