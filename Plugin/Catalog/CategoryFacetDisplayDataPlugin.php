<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Plugin\Catalog;

use Magento\Catalog\Model\Category\DataProvider;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetDisplay;

/**
 * Fills the "TypeSense Filters" rows of the admin category form.
 */
class CategoryFacetDisplayDataPlugin
{
    public const string FIELD = 'typesense_facet_display';

    public function __construct(
        private readonly CategoryFacetDisplay $facetDisplay,
    ) {
    }

    /**
     * @param array<int|string, array<string, mixed>>|null $result
     * @return array<int|string, array<string, mixed>>|null
     */
    public function afterGetData(DataProvider $subject, ?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        $categoryIds = array_keys($result);
        foreach ($categoryIds as $categoryId) {
            if (!is_numeric($categoryId) || (int) $categoryId <= 0) {
                continue;
            }

            $rows = [];
            $modes = $this->facetDisplay->getModes((int) $categoryId);
            foreach ($modes as $field => $mode) {
                $rows[] = ['attribute_code' => $field, 'display_mode' => (string) $mode];
            }
            $result[$categoryId][self::FIELD] = $rows;
        }

        return $result;
    }
}
