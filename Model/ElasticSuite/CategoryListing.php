<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

/**
 * Which products Magento lists in a category: the assigned ones, plus for an anchor category the products
 * assigned to its descendants.
 */
final class CategoryListing
{
    /**
     * @param array<int, array{path: string, is_anchor: bool}> $categories by category id
     * @param array<int, array<int, int>> $assignedPositions position by product id, by category id
     * @param array<int, list<string>> $assignedPaths category paths by product id
     */
    public function __construct(
        private readonly array $categories,
        private readonly array $assignedPositions,
        private readonly array $assignedPaths,
    ) {
    }

    public function hasCategory(int $categoryId): bool
    {
        return isset($this->categories[$categoryId]);
    }

    /**
     * @return array<int, int> position by product id
     */
    public function getAssignedPositions(int $categoryId): array
    {
        return $this->assignedPositions[$categoryId] ?? [];
    }

    /**
     * @param int[] $productIds
     * @return list<int> the given products the category lists only through a descendant
     */
    public function getInheritedProductIds(int $categoryId, array $productIds): array
    {
        return array_values(array_filter(
            $productIds,
            fn (int $productId): bool => !isset($this->assignedPositions[$categoryId][$productId])
                && $this->isInherited($categoryId, $productId),
        ));
    }

    public function isListed(int $categoryId, int $productId): bool
    {
        return isset($this->assignedPositions[$categoryId][$productId]) || $this->isInherited($categoryId, $productId);
    }

    public function isInTree(int $categoryId, string $rootPath): bool
    {
        $path = $this->categories[$categoryId]['path'] ?? null;

        return $path !== null && str_starts_with($path . '/', $rootPath . '/');
    }

    private function isInherited(int $categoryId, int $productId): bool
    {
        $category = $this->categories[$categoryId] ?? null;
        if ($category === null || !$category['is_anchor']) {
            return false;
        }

        $prefix = $category['path'] . '/';
        $paths = $this->assignedPaths[$productId] ?? [];
        foreach ($paths as $path) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
