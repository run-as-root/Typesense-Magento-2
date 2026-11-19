<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\OptionalProductAttributeSource;
use RunAsRoot\TypeSense\Model\Config\Source\ProductAttributeSource;

final class OptionalProductAttributeSourceTest extends TestCase
{
    public function test_prepends_an_empty_option_to_the_product_attributes(): void
    {
        $group = ['label' => 'Text', 'value' => [['value' => 'product_line', 'label' => 'Product line']]];
        $attributeSource = $this->createMock(ProductAttributeSource::class);
        $attributeSource->method('toOptionArray')->willReturn([$group]);

        $options = (new OptionalProductAttributeSource($attributeSource))->toOptionArray();

        self::assertCount(2, $options);
        self::assertSame('', $options[0]['value']);
        self::assertSame('-- None --', (string) $options[0]['label']);
        self::assertSame($group, $options[1]);
    }
}
