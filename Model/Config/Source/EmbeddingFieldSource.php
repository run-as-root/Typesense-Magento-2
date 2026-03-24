<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EmbeddingFieldSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'name', 'label' => 'Product Name'],
            ['value' => 'description', 'label' => 'Description'],
            ['value' => 'short_description', 'label' => 'Short Description'],
            ['value' => 'sku', 'label' => 'SKU'],
            ['value' => 'categories_text', 'label' => 'Category Names (text)'],
        ];
    }
}
