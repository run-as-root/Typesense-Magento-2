<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Protocol implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'http', 'label' => __('HTTP')],
            ['value' => 'https', 'label' => __('HTTPS')],
        ];
    }
}
