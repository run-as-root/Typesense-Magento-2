<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ImageType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'category_page_grid', 'label' => __('Category Page Grid')],
            ['value' => 'category_page_list', 'label' => __('Category Page List')],
            ['value' => 'product_small_image', 'label' => __('Small Image')],
            ['value' => 'product_thumbnail_image', 'label' => __('Thumbnail')],
            ['value' => 'product_base_image', 'label' => __('Base Image')],
        ];
    }
}
