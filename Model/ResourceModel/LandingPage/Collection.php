<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\LandingPage;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use RunAsRoot\TypeSense\Model\Merchandising\LandingPage;
use RunAsRoot\TypeSense\Model\ResourceModel\LandingPage as LandingPageResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(LandingPage::class, LandingPageResource::class);
    }
}
