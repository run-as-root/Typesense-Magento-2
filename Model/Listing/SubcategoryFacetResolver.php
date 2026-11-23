<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Listing;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Facet for the children of the current category, read from the category path fields CategoryResolver
 * indexes (each product carries the paths of its categories and all their ancestors): level 3 as
 * "Top > Child" in categories.lvl1, every deeper level as a full path in categories.lvl2.
 * The counts come from the listing's own Typesense result instead of one product collection per child.
 */
class SubcategoryFacetResolver
{
    private const int TOP_LEVEL = 2;
    private const string PATH_SEPARATOR = ' > ';

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
    ) {
    }

    /**
     * @return array{
     *     field: string, label: string, type: string, searchable: bool, boolean: bool, numeric: bool, prefix: string
     * }|null
     */
    public function resolve(Category $category, int $storeId): ?array
    {
        $level = (int) $category->getLevel();
        if ($level < self::TOP_LEVEL || (int) $category->getChildrenCount() === 0) {
            return null;
        }

        $path = $this->getPath($category, $level, $storeId);
        if ($path === null) {
            return null;
        }

        return [
            'field' => 'categories.lvl' . min($level - 1, 2),
            'label' => (string) __('Category'),
            'type' => 'subcategory',
            'searchable' => false,
            'boolean' => false,
            'numeric' => false,
            'prefix' => $path . self::PATH_SEPARATOR,
        ];
    }

    // Built like CategoryResolver::buildCategoryPath (store-scoped names from the top level down), since the
    // prefix has to equal the indexed value.
    private function getPath(Category $category, int $level, int $storeId): ?string
    {
        $pathIds = $category->getPathIds();
        $ancestorIds = array_slice($pathIds, self::TOP_LEVEL, $level - self::TOP_LEVEL);

        $names = [];
        foreach ($ancestorIds as $ancestorId) {
            try {
                $names[] = (string) $this->categoryRepository->get((int) $ancestorId, $storeId)->getName();
            } catch (NoSuchEntityException) {
                return null;
            }
        }
        $names[] = (string) $category->getName();

        return implode(self::PATH_SEPARATOR, $names);
    }
}
