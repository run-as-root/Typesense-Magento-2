<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OptionalProductAttributeSource implements OptionSourceInterface
{
    public function __construct(
        private readonly ProductAttributeSource $productAttributeSource,
    ) {
    }

    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('-- None --')],
            ...$this->productAttributeSource->toOptionArray(),
        ];
    }
}
