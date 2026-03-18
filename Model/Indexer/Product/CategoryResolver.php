<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Product;

class CategoryResolver implements CategoryResolverInterface
{
    /** @var array<int, CategoryInterface> */
    private array $categoryCache = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
    ) {
    }

    /**
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
        $lvl0 = [];
        $lvl1 = [];
        $lvl2 = [];

        foreach ($categoryIds as $categoryId) {
            $category = $this->loadCategory((int) $categoryId);
            if ($category === null) {
                continue;
            }

            $categoryName = (string) $category->getName();
            $categoryIdsInt[] = (int) $categoryId;
            $categories[] = $categoryName;

            $level = (int) $category->getLevel();
            $path = $this->buildCategoryPath($category);

            if ($level <= 2) {
                $lvl0[] = $categoryName;
            } elseif ($level === 3) {
                $lvl1[] = $path;
            } elseif ($level >= 4) {
                $lvl2[] = $path;
            }
        }

        return [
            'categories' => array_values(array_unique($categories)),
            'category_ids' => array_values(array_unique($categoryIdsInt)),
            'categories.lvl0' => array_values(array_unique($lvl0)),
            'categories.lvl1' => array_values(array_unique($lvl1)),
            'categories.lvl2' => array_values(array_unique($lvl2)),
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

    private function buildCategoryPath(CategoryInterface $category): string
    {
        $names = [];
        $current = $category;

        while ($current !== null && (int) $current->getLevel() > 1) {
            array_unshift($names, (string) $current->getName());

            $parentId = (int) $current->getParentId();
            if ($parentId <= 1) {
                break;
            }

            $current = $this->loadCategory($parentId);
        }

        return implode(' > ', $names);
    }
}
