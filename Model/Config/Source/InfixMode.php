<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class InfixMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'fallback', 'label' => __('Fallback (only when exact search finds nothing)')],
            ['value' => 'always', 'label' => __('Always')],
        ];
    }
}
