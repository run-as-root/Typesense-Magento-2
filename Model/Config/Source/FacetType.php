<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FacetType implements OptionSourceInterface
{
    /** @return list<array{value: string, label: \Magento\Framework\Phrase}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'dropdown', 'label' => __('Dropdown (multi-select)')],
            ['value' => 'checkbox', 'label' => __('Checkbox (Yes/No fields)')],
            ['value' => 'slider', 'label' => __('Range slider (numeric fields)')],
        ];
    }
}
