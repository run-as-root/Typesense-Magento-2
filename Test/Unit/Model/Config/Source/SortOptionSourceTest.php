<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\SortOptionSource;

final class SortOptionSourceTest extends TestCase
{
    public function test_offers_the_fixed_options_and_both_directions_of_every_attribute_used_for_sorting(): void
    {
        $attributes = [];
        foreach (['name' => 'Product Name', 'price' => 'Price', 'volume_percent' => 'Alcohol Vol.-%'] as $code => $label) {
            $attribute = $this->getMockBuilder(Attribute::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getAttributeCode'])
                ->addMethods(['getFrontendLabel'])
                ->getMock();
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('getFrontendLabel')->willReturn($label);
            $attributes[] = $attribute;
        }
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($attributes));
        $collection->expects(self::once())->method('addFieldToFilter')->with('used_for_sort_by', ['eq' => 1]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $options = (new SortOptionSource($factory))->toOptionArray();
        $values = array_column($options, 'value');

        self::assertSame('relevance', $values[0]);
        self::assertContains('name_asc', $values);
        self::assertSame(['attr:volume_percent:asc', 'attr:volume_percent:desc'], array_slice($values, -2));
        self::assertNotContains('attr:name:asc', $values);
        self::assertNotContains('attr:price:asc', $values);
        self::assertSame('Alcohol Vol.-% (ascending)', (string) $options[count($options) - 2]['label']);
    }
}
