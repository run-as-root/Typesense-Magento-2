<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Listing;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\SortableAttributeProvider;
use RunAsRoot\TypeSense\Model\Listing\SortOptionProvider;

final class SortOptionProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private SortableAttributeProvider&MockObject $sortableAttributes;
    private SortOptionProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sortableAttributes = $this->createMock(SortableAttributeProvider::class);
        $this->sortableAttributes->method('getSortableAttributes')->willReturn([
            'volume_percent' => 'float',
            'trade_name' => 'string',
        ]);
        $this->sortableAttributes->method('getStoreLabel')->willReturnMap([
            ['volume_percent', 2, 'Alkohol Vol.-%'],
            ['trade_name', 2, 'Sorte'],
        ]);
        $this->sut = new SortOptionProvider($this->config, $this->sortableAttributes);
    }

    public function test_options_keep_the_configured_order_and_carry_their_magento_sort_code(): void
    {
        $this->config->method('getSortOptionKeys')->with(2)->willReturn([
            'price_desc', 'attr:volume_percent:asc', 'relevance', 'unknown', 'name_asc',
        ]);

        self::assertSame([
            ['label' => 'Price: High to Low', 'value' => 'price:desc', 'code' => 'price'],
            ['label' => 'Alkohol Vol.-%', 'value' => 'volume_percent_sort:asc', 'code' => 'volume_percent'],
            ['label' => 'Relevance', 'value' => '', 'code' => 'position'],
            ['label' => 'Name: A–Z', 'value' => 'name:asc', 'code' => 'name'],
        ], $this->sut->getOptions(2));
    }

    public function test_both_directions_of_an_attribute_get_distinct_labels(): void
    {
        $this->config->method('getSortOptionKeys')->willReturn(['attr:volume_percent:asc', 'attr:volume_percent:desc']);

        self::assertSame(
            ['Alkohol Vol.-%: Low to High', 'Alkohol Vol.-%: High to Low'],
            array_column($this->sut->getOptions(2), 'label'),
        );
    }

    public function test_text_attribute_sorts_products_without_a_value_last_in_both_directions(): void
    {
        $this->config->method('getSortOptionKeys')->willReturn(['attr:trade_name:desc']);

        self::assertSame(
            'trade_name_sort(missing_values: last):desc',
            $this->sut->getOptions(2)[0]['value'],
        );
    }

    public function test_attribute_option_without_a_sortable_attribute_is_left_out(): void
    {
        $this->config->method('getSortOptionKeys')->willReturn(['attr:color:asc', 'newest']);

        self::assertSame(['created_at:desc'], array_column($this->sut->getOptions(2), 'value'));
    }
}
