<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

interface CategoryResolverInterface
{
    /**
     * Return category data for a product.
     *
     * @return array{
     *     categories: string[],
     *     category_ids: int[],
     *     'categories.lvl0': string[],
     *     'categories.lvl1': string[],
     *     'categories.lvl2': string[],
     * }
     */
    public function getCategoryData(Product $product): array;
}
