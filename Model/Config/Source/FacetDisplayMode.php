<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetDisplay;

class FacetDisplayMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => CategoryFacetDisplay::AUTO, 'label' => (string) __('Auto')],
            ['value' => CategoryFacetDisplay::HIDDEN, 'label' => (string) __('Always hidden')],
            ['value' => CategoryFacetDisplay::ALWAYS, 'label' => (string) __('Always shown')],
        ];
    }
}
