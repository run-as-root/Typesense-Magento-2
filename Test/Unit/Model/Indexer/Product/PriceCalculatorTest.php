<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfo\Base as PriceInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\PriceCalculator;

final class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $sut;

    protected function setUp(): void
    {
        $this->sut = new PriceCalculator();
    }

    public function test_final_price_is_the_final_price_amount(): void
    {
        self::assertSame(19.9, $this->sut->getFinalPrice($this->createProduct(['final_price' => 19.9])));
    }

    public function test_regular_price_is_the_regular_price_amount(): void
    {
        self::assertSame(24.9, $this->sut->getRegularPrice($this->createProduct(['regular_price' => 24.9])));
    }

    /** @param array<string, float> $amounts */
    private function createProduct(array $amounts): Product&MockObject
    {
        $priceInfo = $this->createMock(PriceInfo::class);
        $priceInfo->method('getPrice')->willReturnCallback(
            function (string $code) use ($amounts): PriceInterface {
                $amount = $this->createMock(AmountInterface::class);
                $amount->method('getValue')->willReturn($amounts[$code] ?? 0.0);
                $price = $this->createMock(PriceInterface::class);
                $price->method('getAmount')->willReturn($amount);

                return $price;
            },
        );
        $product = $this->createMock(Product::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);

        return $product;
    }
}
