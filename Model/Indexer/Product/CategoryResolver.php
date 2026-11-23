<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Product;

class CategoryResolver implements CategoryResolverInterface
{
    private const int TOP_LEVEL = 2;
    private const string PATH_SEPARATOR = ' > ';

    /** @var array<int, CategoryInterface> */
    private array $categoryCache = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
    ) {
    }

    /**
     * Hierarchical facet paths: every assigned category adds its own path and the paths of all its
     * ancestors, each at its own level (level 2 as a name in lvl0, level 3 as "A > B" in lvl1, level 4
     * and deeper as full paths in lvl2), so a parent's facet value counts the products of its whole subtree.
     *
     * @return array{
     *     categories: string[],
     *     category_ids: int[],
     *     'categories.lvl0': string[],
     *     'categories.lvl1': string[],
     *     'categories.lvl2': string[],
     * }
     */
    public function getCategoryData(Product $product): array
    {
        $categoryIds = $product->getCategoryIds();
        $categories = [];
        $categoryIdsInt = [];
        $paths = [0 => [], 1 => [], 2 => []];

        foreach ($categoryIds as $categoryId) {
            $category = $this->loadCategory((int) $categoryId);
            if ($category === null) {
                continue;
            }

            $categoryIdsInt[] = (int) $categoryId;
            $categories[] = (string) $category->getName();

            $names = [];
            $ancestry = $this->getAncestry($category);
            foreach ($ancestry as $node) {
                $names[] = (string) $node->getName();
                $paths[min((int) $node->getLevel() - self::TOP_LEVEL, 2)][] = implode(self::PATH_SEPARATOR, $names);
            }
        }

        return [
            'categories' => array_values(array_unique($categories)),
            'category_ids' => array_values(array_unique($categoryIdsInt)),
            'categories.lvl0' => array_values(array_unique($paths[0])),
            'categories.lvl1' => array_values(array_unique($paths[1])),
            'categories.lvl2' => array_values(array_unique($paths[2])),
        ];
    }

    private function loadCategory(int $categoryId): ?CategoryInterface
    {
        if (isset($this->categoryCache[$categoryId])) {
            return $this->categoryCache[$categoryId];
        }

        try {
            $category = $this->categoryRepository->get($categoryId);
            $this->categoryCache[$categoryId] = $category;

            return $category;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return list<CategoryInterface> the category and its ancestors from the top level down; the root
     *     catalog and the store root (levels 0 and 1) are not part of any path.
     */
    private function getAncestry(CategoryInterface $category): array
    {
        $ancestry = [];
        $current = $category;

        while ($current !== null && (int) $current->getLevel() >= self::TOP_LEVEL) {
            array_unshift($ancestry, $current);
            $parentId = (int) $current->getParentId();
            $current = (int) $current->getLevel() > self::TOP_LEVEL && $parentId > 1 ? $this->loadCategory($parentId) : null;
        }

        return $ancestry;
    }
}
