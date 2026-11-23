<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\PriceCalculatorInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\Resolver\GroupedChildrenResolver;
use RunAsRoot\TypeSense\Model\Indexer\Product\Resolver\SalableResolverInterface;

final class GroupedChildrenResolverTest extends TestCase
{
    private PriceCalculatorInterface&MockObject $priceCalculator;
    private SalableResolverInterface&MockObject $salableResolver;
    private GroupedChildrenResolver $sut;

    protected function setUp(): void
    {
        $this->priceCalculator = $this->createMock(PriceCalculatorInterface::class);
        $this->salableResolver = $this->createMock(SalableResolverInterface::class);

        $this->sut = new GroupedChildrenResolver($this->priceCalculator, $this->salableResolver);
    }

    public function test_children_keep_associated_product_order_with_prices_stock_and_qty_increment(): void
    {
        $large = $this->createChild(12, 'GIN-070', 'Gin - 0,7 l');
        $small = $this->createChild(11, 'GIN-035', 'Gin - 0,35 l');
        $parent = $this->createParent([$large, $small]);
        $this->priceCalculator->method('getFinalPrice')->willReturnMap([[$large, 39.9], [$small, 21.5]]);
        $this->priceCalculator->method('getRegularPrice')->willReturnMap([[$large, 44.9], [$small, 21.5]]);
        $this->salableResolver->method('isSalable')->willReturnMap([[$large, 3, false], [$small, 3, true]]);
        $this->salableResolver->method('getQtyIncrement')->willReturnMap([[$large, 3, 1.0], [$small, 3, 6.0]]);

        self::assertSame(
            [
                [
                    'id' => 12,
                    'sku' => 'GIN-070',
                    'name' => 'Gin - 0,7 l',
                    'final_price' => 39.9,
                    'regular_price' => 44.9,
                    'in_stock' => false,
                    'qty_increment' => 1.0,
                ],
                [
                    'id' => 11,
                    'sku' => 'GIN-035',
                    'name' => 'Gin - 0,35 l',
                    'final_price' => 21.5,
                    'regular_price' => 21.5,
                    'in_stock' => true,
                    'qty_increment' => 6.0,
                ],
            ],
            $this->sut->getChildren($parent, 3),
        );
    }

    public function test_children_come_from_the_associated_products_of_the_parent(): void
    {
        $parent = $this->createMock(Product::class);
        $typeInstance = $this->createMock(Grouped::class);
        $typeInstance->expects(self::once())->method('getAssociatedProducts')->with($parent)->willReturn([]);
        $parent->method('getTypeInstance')->willReturn($typeInstance);

        $this->sut->getChildren($parent, 3);
    }

    public function test_grouped_product_without_associated_products_has_no_children(): void
    {
        self::assertSame([], $this->sut->getChildren($this->createParent([]), 3));
    }

    /** @param Product[] $children */
    private function createParent(array $children): Product&MockObject
    {
        $typeInstance = $this->createMock(Grouped::class);
        $typeInstance->method('getAssociatedProducts')->willReturn($children);
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeInstance')->willReturn($typeInstance);

        return $parent;
    }

    private function createChild(int $id, string $sku, string $name): Product&MockObject
    {
        $child = $this->createMock(Product::class);
        $child->method('getId')->willReturn($id);
        $child->method('getSku')->willReturn($sku);
        $child->method('getName')->willReturn($name);

        return $child;
    }
}
