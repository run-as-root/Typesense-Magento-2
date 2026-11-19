<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SplitJoinTokens implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'fallback', 'label' => __('Fallback (only when there are no results)')],
            ['value' => 'always', 'label' => __('Always')],
            ['value' => 'off', 'label' => __('Off')],
        ];
    }
}
