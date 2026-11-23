<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attributes plus the core document fields that can be faceted, which the attribute list excludes.
 */
class FacetAttributeSource implements OptionSourceInterface
{
    public function __construct(
        private readonly ProductAttributeSource $productAttributeSource,
    ) {
    }

    public function toOptionArray(): array
    {
        $core = [
            'label' => (string) __('Core Fields'),
            'value' => [
                ['value' => 'price', 'label' => (string) __('Price (price)')],
                ['value' => 'in_stock', 'label' => (string) __('Availability (in_stock)')],
                ['value' => 'is_new', 'label' => (string) __('New (is_new)')],
            ],
        ];

        return array_merge([$core], $this->productAttributeSource->toOptionArray());
    }
}
