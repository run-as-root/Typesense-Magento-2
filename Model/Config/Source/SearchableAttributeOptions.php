<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use RunAsRoot\TypeSense\Model\Search\SearchableAttributesProvider;

class SearchableAttributeOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly SearchableAttributesProvider $searchableAttributesProvider,
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        $attributes = $this->searchableAttributesProvider->getOptions();
        foreach ($attributes as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return $options;
    }
}
