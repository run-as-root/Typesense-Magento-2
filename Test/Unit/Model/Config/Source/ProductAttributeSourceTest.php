<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\ProductAttributeSource;

final class ProductAttributeSourceTest extends TestCase
{
    public function test_does_not_offer_attributes_named_like_core_document_fields(): void
    {
        $attributes = [];
        $codes = ['is_new', 'in_stock', 'sales_count', 'category_ids', 'children', 'categories_text', 'color'];
        foreach ($codes as $code) {
            $attribute = $this->getMockBuilder(Attribute::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getAttributeCode', 'getFrontendInput'])
                ->addMethods(['getFrontendLabel'])
                ->getMock();
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('getFrontendLabel')->willReturn($code);
            $attribute->method('getFrontendInput')->willReturn('boolean');
            $attributes[] = $attribute;
        }
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($attributes));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $options = (new ProductAttributeSource($factory))->toOptionArray();

        self::assertSame(['color'], array_column($options[0]['value'], 'value'));
    }
}
