<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ImageType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'image', 'label' => __('Base Image')],
            ['value' => 'small_image', 'label' => __('Small Image')],
            ['value' => 'thumbnail', 'label' => __('Thumbnail')],
        ];
    }
}
