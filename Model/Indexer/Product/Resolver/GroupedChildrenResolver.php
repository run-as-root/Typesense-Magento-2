<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use RunAsRoot\TypeSense\Model\Indexer\Product\PriceCalculatorInterface;

class GroupedChildrenResolver
{
    public function __construct(
        private readonly PriceCalculatorInterface $priceCalculator,
        private readonly SalableResolverInterface $salableResolver,
    ) {
    }

    /**
     * Children as getAssociatedProducts() returns them (enabled, in the store's website, link position
     * order), so a project's preference for that method applies here too.
     *
     * @return list<array{id: int, sku: string, name: string, final_price: float, regular_price: float,
     *     in_stock: bool, qty_increment: float}>
     */
    public function getChildren(Product $parent, int $storeId): array
    {
        /** @var Grouped $typeInstance */
        $typeInstance = $parent->getTypeInstance();
        $associatedProducts = $typeInstance->getAssociatedProducts($parent);

        $children = [];
        foreach ($associatedProducts as $child) {
            $children[] = [
                'id' => (int) $child->getId(),
                'sku' => (string) $child->getSku(),
                'name' => (string) $child->getName(),
                'final_price' => $this->priceCalculator->getFinalPrice($child),
                'regular_price' => $this->priceCalculator->getRegularPrice($child),
                'in_stock' => $this->salableResolver->isSalable($child, $storeId),
                'qty_increment' => $this->salableResolver->getQtyIncrement($child, $storeId),
            ];
        }

        return $children;
    }
}
