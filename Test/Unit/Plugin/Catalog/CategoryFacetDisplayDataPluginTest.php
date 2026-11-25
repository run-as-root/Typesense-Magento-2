<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Plugin\Catalog;

use Magento\Catalog\Model\Category\DataProvider;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetDisplay;
use RunAsRoot\TypeSense\Plugin\Catalog\CategoryFacetDisplayDataPlugin;

final class CategoryFacetDisplayDataPluginTest extends TestCase
{
    public function test_existing_categories_get_their_rows_and_new_ones_none(): void
    {
        $facetDisplay = $this->createMock(CategoryFacetDisplay::class);
        $facetDisplay->method('getModes')->with(139)->willReturn(['gift_for_woman' => 1, 'taste' => 2]);
        $sut = new CategoryFacetDisplayDataPlugin($facetDisplay);

        $result = $sut->afterGetData(
            $this->createMock(DataProvider::class),
            [139 => ['name' => 'Geschenke für Männer'], '' => ['parent' => 3]],
        );

        self::assertSame([
            ['attribute_code' => 'gift_for_woman', 'display_mode' => '1'],
            ['attribute_code' => 'taste', 'display_mode' => '2'],
        ], $result[139]['typesense_facet_display']);
        self::assertArrayNotHasKey('typesense_facet_display', $result['']);
        self::assertNull($sut->afterGetData($this->createMock(DataProvider::class), null));
    }
}
