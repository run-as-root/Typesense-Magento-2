<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use RunAsRoot\TypeSense\Model\Listing\CategoryFacetDisplay;

/**
 * Copies ElasticSuite's per-category filter settings ("always hidden" / "always displayed"), whose mode values
 * CategoryFacetDisplay shares. A category's settings replace the module's for that category; settings are global
 * in both, so a store view option does not narrow them. ElasticSuite's coverage rule for "auto" filters is not
 * carried over.
 */
class CategoryFilterImporter
{
    private const string REASON_UNKNOWN_MODE = 'unknown ElasticSuite display mode';

    public function __construct(
        private readonly ElasticSuiteReader $reader,
        private readonly CategoryFacetDisplay $facetDisplay,
    ) {
    }

    public function import(ImportReport $report, bool $dryRun, ?string $storeCode): void
    {
        if (!$this->reader->hasTables(ElasticSuiteReader::CATEGORY_FILTERABLE_ATTRIBUTE)) {
            $report->note(ImportReport::FILTERS, 'ElasticSuite category filter table not found, nothing to import');

            return;
        }
        if ($storeCode !== null) {
            $report->note(ImportReport::FILTERS, 'Category filter settings are global: imported for every store view');
        }

        $byCategory = $this->groupByCategory($this->reader->getCategoryFilterDisplayModes(), $report);
        foreach ($byCategory as $categoryId => $modes) {
            ksort($modes);
            $current = $this->facetDisplay->getModes($categoryId);
            if ($current === $modes) {
                $report->unchanged(ImportReport::FILTERS);
                continue;
            }

            if ($current === []) {
                $report->created(ImportReport::FILTERS);
            } else {
                $report->updated(ImportReport::FILTERS);
            }
            if (!$dryRun) {
                $this->facetDisplay->replace($categoryId, $modes);
            }
        }
    }

    /**
     * "Auto" rows carry no setting and are left out; a category with only those has nothing to import.
     *
     * @param list<array{category_id: int, attribute_code: string, display_mode: int}> $rows
     * @return array<int, array<string, int>>
     */
    private function groupByCategory(array $rows, ImportReport $report): array
    {
        $byCategory = [];
        foreach ($rows as $row) {
            $mode = $row['display_mode'];
            if (!in_array($mode, CategoryFacetDisplay::MODES, true)) {
                $report->skipped(
                    ImportReport::FILTERS,
                    sprintf('category %d %s (%d)', $row['category_id'], $row['attribute_code'], $mode),
                    self::REASON_UNKNOWN_MODE,
                );
                continue;
            }
            if ($mode !== CategoryFacetDisplay::AUTO) {
                $byCategory[$row['category_id']][$row['attribute_code']] = $mode;
            }
        }

        return $byCategory;
    }
}
