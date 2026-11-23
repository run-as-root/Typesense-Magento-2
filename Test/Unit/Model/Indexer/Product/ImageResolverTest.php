<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ImageResolver;

final class ImageResolverTest extends TestCase
{
    private ImageHelper&MockObject $imageHelper;
    private TypeSenseConfigInterface&MockObject $config;
    private ImageResolver $sut;

    protected function setUp(): void
    {
        $this->imageHelper = $this->createMock(ImageHelper::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new ImageResolver($this->imageHelper, $this->config);
    }

    public function test_image_url_uses_configured_listing_image_id(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getImage')->willReturn('/a/b/shoe.jpg');
        $this->config->method('getListingImageId')->willReturn('category_page_list');
        $this->imageHelper->expects(self::once())
            ->method('init')
            ->with($product, 'category_page_list')
            ->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('https://example.com/media/cache/shoe.jpg');

        self::assertSame('https://example.com/media/cache/shoe.jpg', $this->sut->getImageUrl($product));
    }

    public function test_returns_empty_string_without_image(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getImage')->willReturn('no_selection');
        $this->imageHelper->expects(self::never())->method('init');

        self::assertSame('', $this->sut->getImageUrl($product));
    }

    public function test_returns_empty_string_when_helper_fails(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getImage')->willReturn('/a/b/shoe.jpg');
        $this->config->method('getListingImageId')->willReturn('category_page_grid');
        $this->imageHelper->method('init')->willThrowException(new \RuntimeException('boom'));

        self::assertSame('', $this->sut->getImageUrl($product));
    }
}
