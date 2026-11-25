<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\ElasticSuite;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryFilterImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\ElasticSuiteReader;
use RunAsRoot\TypeSense\Model\ElasticSuite\ImportReport;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetDisplay;

final class CategoryFilterImporterTest extends TestCase
{
    private ElasticSuiteReader&MockObject $reader;
    private CategoryFacetDisplay&MockObject $facetDisplay;
    private CategoryFilterImporter $sut;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ElasticSuiteReader::class);
        $this->reader->method('hasTables')->willReturn(true);
        $this->facetDisplay = $this->createMock(CategoryFacetDisplay::class);
        $this->sut = new CategoryFilterImporter($this->reader, $this->facetDisplay);
    }

    public function test_hidden_and_always_shown_filters_are_copied_per_category(): void
    {
        $this->reader->method('getCategoryFilterDisplayModes')->willReturn([
            ['category_id' => 139, 'attribute_code' => 'gift_for_woman', 'display_mode' => 1],
            ['category_id' => 139, 'attribute_code' => 'taste', 'display_mode' => 2],
            ['category_id' => 139, 'attribute_code' => 'color', 'display_mode' => 0],
            ['category_id' => 138, 'attribute_code' => 'gift_for_man', 'display_mode' => 1],
        ]);
        $this->facetDisplay->method('getModes')->willReturn([]);
        $replaced = [];
        $this->facetDisplay->expects(self::exactly(2))->method('replace')->willReturnCallback(
            static function (int $categoryId, array $modes) use (&$replaced): void {
                $replaced[$categoryId] = $modes;
            },
        );
        $report = new ImportReport();

        $this->sut->import($report, false, null);

        self::assertSame([
            139 => ['gift_for_woman' => CategoryFacetDisplay::HIDDEN, 'taste' => CategoryFacetDisplay::ALWAYS],
            138 => ['gift_for_man' => CategoryFacetDisplay::HIDDEN],
        ], $replaced);
        self::assertSame(2, $report->getSections()[ImportReport::FILTERS]['created']);
    }

    public function test_rerun_reports_unchanged_and_changed_categories_without_duplicating(): void
    {
        $this->reader->method('getCategoryFilterDisplayModes')->willReturn([
            ['category_id' => 139, 'attribute_code' => 'taste', 'display_mode' => 2],
            ['category_id' => 139, 'attribute_code' => 'gift_for_woman', 'display_mode' => 1],
            ['category_id' => 138, 'attribute_code' => 'gift_for_man', 'display_mode' => 1],
        ]);
        $this->facetDisplay->method('getModes')->willReturnMap([
            [139, ['gift_for_woman' => 1, 'taste' => 2]],
            [138, ['gift_for_man' => 2]],
        ]);
        $this->facetDisplay->expects(self::once())->method('replace')->with(138, ['gift_for_man' => 1]);
        $report = new ImportReport();

        $this->sut->import($report, false, null);

        $section = $report->getSections()[ImportReport::FILTERS];
        self::assertSame([0, 1, 1], [$section['created'], $section['updated'], $section['unchanged']]);
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $this->reader->method('getCategoryFilterDisplayModes')->willReturn([
            ['category_id' => 139, 'attribute_code' => 'gift_for_woman', 'display_mode' => 1],
        ]);
        $this->facetDisplay->method('getModes')->willReturn([]);
        $this->facetDisplay->expects(self::never())->method('replace');
        $report = new ImportReport();

        $this->sut->import($report, true, null);

        self::assertSame(1, $report->getSections()[ImportReport::FILTERS]['created']);
    }

    public function test_unknown_modes_are_skipped(): void
    {
        $this->reader->method('getCategoryFilterDisplayModes')->willReturn([
            ['category_id' => 139, 'attribute_code' => 'taste', 'display_mode' => 7],
        ]);
        $this->facetDisplay->expects(self::never())->method('replace');
        $report = new ImportReport();

        $this->sut->import($report, false, null);

        $section = $report->getSections()[ImportReport::FILTERS];
        self::assertSame(['category 139 taste (7)'], $section['skipped']['unknown ElasticSuite display mode']);
    }

    public function test_a_store_view_run_writes_nothing_as_the_settings_are_global(): void
    {
        $this->reader->expects(self::never())->method('getCategoryFilterDisplayModes');
        $this->facetDisplay->expects(self::never())->method('replace');
        $report = new ImportReport();

        $this->sut->import($report, false, 'de');

        self::assertStringContainsString('without --store', $report->getSections()[ImportReport::FILTERS]['notes'][0]);
    }

    public function test_settings_stored_in_another_order_count_as_unchanged(): void
    {
        $this->reader->method('getCategoryFilterDisplayModes')->willReturn([
            ['category_id' => 7, 'attribute_code' => 'alc_vol', 'display_mode' => 1],
            ['category_id' => 7, 'attribute_code' => 'alcohol', 'display_mode' => 2],
        ]);
        $this->facetDisplay->method('getModes')->willReturn(['alcohol' => 2, 'alc_vol' => 1]);
        $this->facetDisplay->expects(self::never())->method('replace');
        $report = new ImportReport();

        $this->sut->import($report, false, null);

        self::assertSame(1, $report->getSections()[ImportReport::FILTERS]['unchanged']);
    }

    public function test_missing_elasticsuite_table_is_noted(): void
    {
        $reader = $this->createMock(ElasticSuiteReader::class);
        $reader->method('hasTables')->willReturn(false);
        $reader->expects(self::never())->method('getCategoryFilterDisplayModes');
        $report = new ImportReport();

        (new CategoryFilterImporter($reader, $this->facetDisplay))->import($report, false, null);

        self::assertStringContainsString('not found', $report->getSections()[ImportReport::FILTERS]['notes'][0]);
    }
}
