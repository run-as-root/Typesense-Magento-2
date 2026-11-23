<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ImageType implements OptionSourceInterface
{
    public const array LABELS = [
        'category_page_grid' => 'Category Page Grid',
        'category_page_list' => 'Category Page List',
        'product_small_image' => 'Small Image',
        'product_thumbnail_image' => 'Thumbnail',
        'product_base_image' => 'Base Image',
    ];

    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }

        return $options;
    }
}
