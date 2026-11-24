<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;

class CategoryResolver implements CategoryResolverInterface
{
    private const int TOP_LEVEL = 2;
    private const string PATH_SEPARATOR = ' > ';

    // Keyed by store id: the indexer emulates one store after another in the same process, and names and
    // is_active are store-scoped. Missing categories are cached as null.
    /** @var array<int, array<int, CategoryInterface|null>> */
    private array $categoryCache = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Hierarchical facet paths: every assigned category adds its own path and the paths of all its
     * ancestors, each at its own level (level 2 as a name in lvl0, level 3 as "A > B" in lvl1, level 4
     * and deeper as full paths in lvl2), so a parent's facet value counts the products of its whole subtree.
     * category_ids also holds the anchor ancestors, so `category_ids:=<anchor>` lists the anchor's subtree.
     * Inactive categories (in the store being indexed) add no name and no path of their own; an inactive
     * assigned category adds nothing for its ancestors either, like Magento's category product index.
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
        $anchorIds = [];
        $paths = [0 => [], 1 => [], 2 => []];
        $storeId = (int) $this->storeManager->getStore()->getId();

        foreach ($categoryIds as $categoryId) {
            $category = $this->loadCategory((int) $categoryId, $storeId);
            if ($category === null) {
                continue;
            }

            // Kept even when inactive: an inactive category has no storefront page, but the merchandiser can
            // still preview its products before it is enabled.
            $categoryIdsInt[] = (int) $categoryId;
            if (!$category->getIsActive()) {
                continue;
            }
            $categories[] = (string) $category->getName();

            $names = [];
            $ancestry = $this->getAncestry($category, $storeId);
            foreach ($ancestry as $node) {
                // An inactive ancestor's name stays in the deeper paths, so every path is the category's full
                // location in the tree.
                $names[] = (string) $node->getName();
                if (!$node->getIsActive()) {
                    continue;
                }
                $paths[min((int) $node->getLevel() - self::TOP_LEVEL, 2)][] = implode(self::PATH_SEPARATOR, $names);
                if ($node !== $category && $this->listsSubtree($node)) {
                    $anchorIds[] = (int) $node->getId();
                }
            }
        }

        return [
            'categories' => array_values(array_unique($categories)),
            'category_ids' => array_values(array_unique([...$categoryIdsInt, ...$anchorIds])),
            'categories.lvl0' => array_values(array_unique($paths[0])),
            'categories.lvl1' => array_values(array_unique($paths[1])),
            'categories.lvl2' => array_values(array_unique($paths[2])),
        ];
    }

    // Magento's anchor index (catalog_category_product_index) lists a product in every active anchor ancestor
    // of an active assigned category, whether or not the categories in between are anchors.
    private function listsSubtree(CategoryInterface $category): bool
    {
        return $category instanceof AbstractModel
            && (bool) $category->getData('is_anchor')
            && (bool) $category->getIsActive();
    }

    private function loadCategory(int $categoryId, int $storeId): ?CategoryInterface
    {
        if (array_key_exists($categoryId, $this->categoryCache[$storeId] ?? [])) {
            return $this->categoryCache[$storeId][$categoryId];
        }

        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
        } catch (\Exception) {
            $category = null;
        }
        $this->categoryCache[$storeId][$categoryId] = $category;

        return $category;
    }

    /**
     * @return list<CategoryInterface> the category and its ancestors from the top level down; the root
     *     catalog and the store root (levels 0 and 1) are not part of any path.
     */
    private function getAncestry(CategoryInterface $category, int $storeId): array
    {
        $ancestry = [];
        $current = $category;

        while ($current !== null && (int) $current->getLevel() >= self::TOP_LEVEL) {
            array_unshift($ancestry, $current);
            $parentId = (int) $current->getParentId();
            $current = (int) $current->getLevel() > self::TOP_LEVEL && $parentId > 1
                ? $this->loadCategory($parentId, $storeId)
                : null;
        }

        return $ancestry;
    }
}
