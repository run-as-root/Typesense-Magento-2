<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\AttributeResolver;

final class AttributeResolverTest extends TestCase
{
    private AttributeResolver $sut;

    protected function setUp(): void
    {
        $material = $this->createMock(Attribute::class);
        $material->method('getAttributeCode')->willReturn('material');
        $material->method('getFrontendInput')->willReturn('multiselect');
        $color = $this->createMock(Attribute::class);
        $color->method('getAttributeCode')->willReturn('color');
        $color->method('getFrontendInput')->willReturn('select');

        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$material, $color]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(['material', 'color']);

        $this->sut = new AttributeResolver($factory, $config);
    }

    public function test_multiselect_attribute_is_indexed_as_label_array(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getAttributeText')->willReturnMap([
            ['material', ['Wood', 'Glass']],
            ['color', 'Red'],
        ]);

        self::assertSame(['material' => ['Wood', 'Glass'], 'color' => ['Red']], $this->sut->getExtraAttributes($product));
    }

    public function test_multiselect_with_single_option_is_still_an_array(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getAttributeText')->willReturnMap([
            ['material', 'Wood'],
            ['color', false],
        ]);

        self::assertSame(['material' => ['Wood']], $this->sut->getExtraAttributes($product));
    }

    public function test_attributes_named_like_core_document_fields_are_not_extra(): void
    {
        $isNew = $this->createMock(Attribute::class);
        $isNew->method('getAttributeCode')->willReturn('is_new');
        $isNew->method('getFrontendInput')->willReturn('boolean');
        $categoryIds = $this->createMock(Attribute::class);
        $categoryIds->method('getAttributeCode')->willReturn('category_ids');
        $categoryIds->method('getFrontendInput')->willReturn('text');
        $color = $this->createMock(Attribute::class);
        $color->method('getAttributeCode')->willReturn('color');
        $color->method('getFrontendInput')->willReturn('select');
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$isNew, $categoryIds, $color]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(['is_new', 'category_ids', 'color']);
        $product = $this->createMock(Product::class);
        $product->method('getAttributeText')->willReturnMap([['is_new', 'Yes'], ['color', 'Red']]);
        $product->method('getData')->willReturn('5');

        $extra = (new AttributeResolver($factory, $config))->getExtraAttributes($product);

        self::assertSame(['color' => ['Red']], $extra);
    }

    public function test_text_attribute_values_are_a_single_string_in_a_list(): void
    {
        $abv = $this->createMock(Attribute::class);
        $abv->method('getAttributeCode')->willReturn('abv');
        $abv->method('getFrontendInput')->willReturn('text');
        $empty = $this->createMock(Attribute::class);
        $empty->method('getAttributeCode')->willReturn('origin');
        $empty->method('getFrontendInput')->willReturn('text');
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$abv, $empty]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(['abv', 'origin']);
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnMap([['abv', null, 40.5], ['origin', null, '']]);

        self::assertSame(['abv' => ['40.5']], (new AttributeResolver($factory, $config))->getExtraAttributes($product));
    }
}
