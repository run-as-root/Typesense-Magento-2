<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SortOptionSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'relevance', 'label' => __('Relevance')],
            ['value' => 'price_asc', 'label' => __('Price: Low to High')],
            ['value' => 'price_desc', 'label' => __('Price: High to Low')],
            ['value' => 'newest', 'label' => __('Newest')],
            ['value' => 'name_asc', 'label' => __('Name: A–Z')],
            ['value' => 'name_desc', 'label' => __('Name: Z–A')],
            ['value' => 'best_selling', 'label' => __('Best Selling')],
            ['value' => 'top_rated', 'label' => __('Top Rated')],
            ['value' => 'most_reviewed', 'label' => __('Most Reviewed')],
        ];
    }
}
