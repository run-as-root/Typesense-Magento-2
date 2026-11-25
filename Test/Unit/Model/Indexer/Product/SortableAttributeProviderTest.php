<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Attribute\Source\SourceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\SortableAttributeProvider;

final class SortableAttributeProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private EavConfig&MockObject $eavConfig;
    private SortableAttributeProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->eavConfig = $this->createMock(EavConfig::class);
        $attributes = [
            'volume_percent' => $this->createAttribute(247, 'select', 'int', ['8.0 %', '40.0 %', '4,5 %']),
            'taste' => $this->createAttribute(250, 'select', 'int', ['mild', '12 Jahre']),
            'bottle_size' => $this->createAttribute(251, 'text', 'decimal'),
            'trade_name' => $this->createAttribute(252, 'text', 'varchar'),
            'unknown' => $this->createAttribute(null, 'text', 'varchar'),
        ];
        $this->eavConfig->method('getAttribute')->willReturnCallback(
            static fn (string $entityType, string $code): Attribute => $attributes[$code] ?? $attributes['unknown'],
        );
        $this->sut = new SortableAttributeProvider($this->config, $this->eavConfig);
    }

    public function test_sortable_attributes_come_from_the_configured_attribute_sort_options(): void
    {
        $this->config->method('getSortOptionKeys')->with(2)->willReturn([
            'relevance', 'attr:volume_percent:asc', 'attr:volume_percent:desc', 'attr:taste:asc',
            'attr:bottle_size:desc', 'attr:trade_name:asc', 'attr:unknown:asc', 'attr:name:asc', 'attr:bad code:asc',
            'attr:color:sideways',
        ]);

        self::assertSame(
            ['volume_percent' => 'float', 'taste' => 'string', 'bottle_size' => 'float', 'trade_name' => 'string'],
            $this->sut->getSortableAttributes(2),
        );
    }

    public function test_option_keys_are_parsed_into_attribute_and_direction(): void
    {
        self::assertSame(['volume_percent', 'desc'], SortableAttributeProvider::parseOptionKey('attr:volume_percent:desc'));
        self::assertNull(SortableAttributeProvider::parseOptionKey('price_asc'));
        self::assertNull(SortableAttributeProvider::parseOptionKey('attr:volume_percent'));
        self::assertSame('volume_percent_sort', SortableAttributeProvider::getFieldName('volume_percent'));
    }

    public function test_numeric_sort_field_holds_the_number_of_the_own_value(): void
    {
        $this->config->method('getSortOptionKeys')->willReturn(['attr:volume_percent:asc']);

        self::assertSame(
            ['volume_percent_sort' => 40.0],
            $this->sut->buildSortFields(['volume_percent' => ['40.0 %']], ['volume_percent' => ['40.0 %', '4,5 %']], 1),
        );
    }

    public function test_composite_product_without_an_own_value_takes_the_lowest_child_value(): void
    {
        $this->config->method('getSortOptionKeys')->willReturn(['attr:volume_percent:asc', 'attr:trade_name:asc']);

        self::assertSame(
            ['volume_percent_sort' => 4.5, 'trade_name_sort' => 'Jagertee'],
            $this->sut->buildSortFields([], ['volume_percent' => ['40.0 %', '4,5 %'], 'trade_name' => ['Likör', 'Jagertee']], 1),
        );
    }

    public function test_values_that_are_no_numbers_are_left_out_of_a_numeric_sort_field(): void
    {
        $this->config->method('getSortOptionKeys')->willReturn(['attr:volume_percent:asc', 'attr:bottle_size:asc']);

        self::assertSame(
            ['bottle_size_sort' => 0.7],
            $this->sut->buildSortFields(['volume_percent' => ['alkoholfrei'], 'bottle_size' => ['0.7000']], [], 1),
        );
    }

    public function test_no_sort_field_without_any_value(): void
    {
        $this->config->method('getSortOptionKeys')->willReturn(['attr:volume_percent:asc']);

        self::assertSame([], $this->sut->buildSortFields([], [], 1));
    }

    public function test_store_label_of_a_sortable_attribute(): void
    {
        self::assertSame('Alkohol Vol.-%', $this->sut->getStoreLabel('volume_percent', 2));
        self::assertNull($this->sut->getStoreLabel('unknown', 2));
    }

    /** @param list<string> $optionLabels */
    private function createAttribute(?int $id, string $input, string $backendType, array $optionLabels = []): Attribute&MockObject
    {
        $options = [['value' => '', 'label' => ' ']];
        foreach ($optionLabels as $i => $label) {
            $options[] = ['value' => (string) ($i + 1), 'label' => $label];
        }
        $source = $this->createMock(SourceInterface::class);
        $source->method('getAllOptions')->willReturn($options);
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getId')->willReturn($id);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getBackendType')->willReturn($backendType);
        $attribute->method('usesSource')->willReturn($optionLabels !== []);
        $attribute->method('getSource')->willReturn($source);
        $attribute->method('getStoreLabel')->willReturn('Alkohol Vol.-%');
        $attribute->method('getEntityTypeId')->willReturn(4);

        return $attribute;
    }
}
