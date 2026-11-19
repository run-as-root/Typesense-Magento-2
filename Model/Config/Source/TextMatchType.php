<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TextMatchType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'max_weight', 'label' => __('Max Weight')],
            ['value' => 'max_score', 'label' => __('Max Score')],
            ['value' => 'sum_score', 'label' => __('Sum Score')],
        ];
    }
}
