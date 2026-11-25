<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use Magento\Store\Model\Store;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandisingFactory;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising\CollectionFactory;

/**
 * Copies ElasticSuite's merchandiser into Magento and the module, so indexing never reads ElasticSuite's table:
 * the "All Store Views" positions become Magento category positions (see CategoryPositionPlanner), blacklisted
 * products become "hide" rows of the category merchandiser for every store view the row applies to. Positions a
 * store view kept for itself ("use_store_positions") cannot be expressed, as Magento positions are global.
 */
class CategoryPositionImporter
{
    private const string ACTION_HIDE = 'hide';
    private const string REASON_STORE_POSITIONS = 'store view positions (Magento positions are global)';
    private const string REASON_STORE_ROW_IGNORED = 'store view row ignored by ElasticSuite (use_store_positions is off)';

    public function __construct(
        private readonly ElasticSuiteReader $reader,
        private readonly CategoryProductTable $table,
        private readonly CategoryPositionPlanner $planner,
        private readonly StoreScope $storeScope,
        private readonly CollectionFactory $merchandisingCollectionFactory,
        private readonly CategoryMerchandisingFactory $merchandisingFactory,
        private readonly CategoryMerchandisingRepositoryInterface $merchandisingRepository,
        private readonly CategoryMerchandisingSync $merchandisingSync,
    ) {
    }

    public function import(ImportReport $report, bool $dryRun, ?string $storeCode): void
    {
        if (!$this->reader->hasTables(ElasticSuiteReader::CATEGORY_PRODUCT_POSITION)) {
            $report->note(ImportReport::POSITIONS, 'ElasticSuite category position table not found, nothing to import');

            return;
        }

        $rows = $this->reader->getCategoryProductPositions();
        $byCategory = [];
        foreach ($rows as $row) {
            $byCategory[$row['category_id']][$row['store_id']][$row['product_id']] = $row;
        }
        $categoryIds = array_keys($byCategory);
        $productIds = array_values(array_unique(array_column($rows, 'product_id')));
        $context = new CategoryListing(
            $this->table->getCategories($categoryIds),
            $this->table->getAssignedPositions($categoryIds),
            $this->table->getAssignedCategoryPaths($productIds),
        );
        $storePositionCategories = $this->reader->getStorePositionCategoryIds();

        if ($storeCode === null) {
            $this->importPositions($byCategory, $storePositionCategories, $context, $report, $dryRun);
        } else {
            $report->note(
                ImportReport::POSITIONS,
                'Magento category positions are global: run --positions without --store to import them',
            );
        }

        $stores = $this->storeScope->getStores($storeCode);
        $this->importHides($byCategory, $storePositionCategories, $context, $stores, $report, $dryRun);
    }

    /**
     * @param array<int, array<int, array<int, array{position: int|null, is_blacklisted: bool}>>> $byCategory
     * @param array<int, list<int>> $storePositionCategories
     */
    private function importPositions(
        array $byCategory,
        array $storePositionCategories,
        CategoryListing $context,
        ImportReport $report,
        bool $dryRun,
    ): void {
        $moved = 0;
        $assigned = 0;

        foreach ($byCategory as $categoryId => $storeRows) {
            $this->reportStoreViewPositions($categoryId, $storeRows, $storePositionCategories, $report);

            $esPositions = [];
            $globalRows = $storeRows[0] ?? [];
            foreach ($globalRows as $productId => $row) {
                if (!$row['is_blacklisted']) {
                    $esPositions[$productId] = $row['position'];
                }
            }
            if ($esPositions === [] || !$context->hasCategory($categoryId)) {
                continue;
            }

            $plan = $this->planner->plan(
                $esPositions,
                $context->getAssignedPositions($categoryId),
                $context->getInheritedProductIds($categoryId, array_keys($esPositions)),
            );
            $report->created(ImportReport::POSITIONS, count($plan['created']));
            $report->updated(ImportReport::POSITIONS, count($plan['updated']));
            $report->unchanged(ImportReport::POSITIONS, count($plan['unchanged']));
            foreach ($plan['skipped'] as $skip) {
                $report->skipped(
                    ImportReport::POSITIONS,
                    sprintf('category %d product %d', $categoryId, $skip['product']),
                    $skip['reason'],
                );
            }
            $moved += count($plan['moved']);
            $assigned += count($plan['created']);

            if (!$dryRun) {
                $this->table->savePositions($categoryId, $plan['positions']);
            }
        }

        if ($assigned > 0) {
            $report->note(ImportReport::POSITIONS, sprintf(
                '%d product(s) an anchor category only inherited from a subcategory were assigned to it'
                . ' to hold their position',
                $assigned,
            ));
        }
        if ($moved > 0) {
            $report->note(ImportReport::POSITIONS, sprintf(
                '%d assigned product(s) without an ElasticSuite position moved behind the positioned ones',
                $moved,
            ));
        }
    }

    /**
     * @param array<int, array<int, array{position: int|null, is_blacklisted: bool}>> $storeRows
     * @param array<int, list<int>> $storePositionCategories
     */
    private function reportStoreViewPositions(
        int $categoryId,
        array $storeRows,
        array $storePositionCategories,
        ImportReport $report,
    ): void {
        foreach ($storeRows as $storeId => $rows) {
            if ($storeId === 0) {
                continue;
            }
            $usesOwnPositions = in_array($categoryId, $storePositionCategories[$storeId] ?? [], true);
            foreach ($rows as $productId => $row) {
                // Blacklisted rows of a store view with its own positions are imported as hidden products.
                if ($usesOwnPositions && $row['is_blacklisted']) {
                    continue;
                }
                $report->skipped(
                    ImportReport::POSITIONS,
                    sprintf('category %d product %d store view %d', $categoryId, $productId, $storeId),
                    $usesOwnPositions ? self::REASON_STORE_POSITIONS : self::REASON_STORE_ROW_IGNORED,
                );
            }
        }
    }

    /**
     * @param array<int, array<int, array<int, array{position: int|null, is_blacklisted: bool}>>> $byCategory
     * @param array<int, list<int>> $storePositionCategories
     * @param list<Store> $stores
     */
    private function importHides(
        array $byCategory,
        array $storePositionCategories,
        CategoryListing $context,
        array $stores,
        ImportReport $report,
        bool $dryRun,
    ): void {
        $existing = $this->getExistingActions(array_keys($byCategory));
        $touched = [];

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $rootPath = '1/' . (int) $store->getRootCategoryId();

            foreach ($byCategory as $categoryId => $storeRows) {
                if (!$context->isInTree($categoryId, $rootPath)) {
                    continue;
                }
                $usesOwnPositions = in_array($categoryId, $storePositionCategories[$storeId] ?? [], true);
                $rows = $usesOwnPositions ? ($storeRows[$storeId] ?? []) : ($storeRows[0] ?? []);

                foreach ($rows as $productId => $row) {
                    if (!$row['is_blacklisted']) {
                        continue;
                    }
                    $subject = sprintf('category %d product %d store view %d', $categoryId, $productId, $storeId);
                    $action = $existing[$categoryId][$storeId][$productId] ?? null;

                    if (!$context->isListed($categoryId, $productId)) {
                        $report->skipped(ImportReport::HIDES, $subject, 'not listed in the category');
                    } elseif ($action === self::ACTION_HIDE) {
                        $report->unchanged(ImportReport::HIDES);
                    } elseif ($action !== null) {
                        $report->skipped(ImportReport::HIDES, $subject, 'pinned in the category merchandiser');
                    } else {
                        $report->created(ImportReport::HIDES);
                        if (!$dryRun) {
                            $this->saveHide($categoryId, $storeId, $productId);
                            $touched[$categoryId . '|' . $storeId] = [$categoryId, $store];
                        }
                    }
                }
            }
        }

        foreach ($touched as [$categoryId, $store]) {
            try {
                $this->merchandisingSync->sync($categoryId, (int) $store->getId(), (string) $store->getCode());
            } catch (\Throwable $e) {
                $report->note(ImportReport::HIDES, sprintf(
                    'hidden products of category %d saved for store view %s but not synced (%s);'
                    . ' they are applied with the next product reindex',
                    $categoryId,
                    $store->getCode(),
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, array<int, array<int, string>>> merchandiser action by product, store and category id
     */
    private function getExistingActions(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $collection = $this->merchandisingCollectionFactory->create();
        $collection->addFieldToFilter('category_id', ['in' => $categoryIds]);
        /** @var CategoryMerchandisingInterface[] $items */
        $items = $collection->getItems();

        $actions = [];
        foreach ($items as $item) {
            $actions[$item->getCategoryId()][$item->getStoreId()][$item->getProductId()] = $item->getAction();
        }

        return $actions;
    }

    private function saveHide(int $categoryId, int $storeId, int $productId): void
    {
        $row = $this->merchandisingFactory->create();
        $row->setCategoryId($categoryId)
            ->setStoreId($storeId)
            ->setProductId($productId)
            ->setPosition(0)
            ->setAction(self::ACTION_HIDE);
        $this->merchandisingRepository->save($row);
    }
}
