<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\ElasticSuite;

use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryPositionImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryPositionPlanner;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryProductTable;
use RunAsRoot\TypeSense\Model\ElasticSuite\ElasticSuiteReader;
use RunAsRoot\TypeSense\Model\ElasticSuite\ImportReport;
use RunAsRoot\TypeSense\Model\ElasticSuite\StoreScope;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandising;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandisingFactory;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising\Collection;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising\CollectionFactory;

final class CategoryPositionImporterTest extends TestCase
{
    private ElasticSuiteReader&MockObject $reader;
    private CategoryProductTable&MockObject $table;
    private StoreScope&MockObject $storeScope;
    private CategoryMerchandisingRepositoryInterface&MockObject $repository;
    private CategoryMerchandisingSync&MockObject $sync;
    /** @var list<array{category_id: int, product_id: int, store_id: int, position: int|null, is_blacklisted: bool}> */
    private array $rows = [];
    /** @var array<int, list<int>> */
    private array $storePositionCategories = [];
    /** @var list<CategoryMerchandisingInterface> */
    private array $existingMerchandising = [];
    /** @var array<int, array<int, int>> */
    private array $saved = [];
    /** @var list<CategoryMerchandisingInterface> */
    private array $savedMerchandising = [];
    private ImportReport $report;
    private CategoryPositionImporter $sut;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ElasticSuiteReader::class);
        $this->reader->method('hasTables')->willReturn(true);
        $this->reader->method('getCategoryProductPositions')->willReturnCallback(fn (): array => $this->rows);
        $this->reader->method('getStorePositionCategoryIds')->willReturnCallback(
            fn (): array => $this->storePositionCategories,
        );

        $this->table = $this->createMock(CategoryProductTable::class);
        $this->table->method('getCategories')->willReturn([
            10 => ['path' => '1/2/10', 'is_anchor' => true],
            11 => ['path' => '1/2/10/11', 'is_anchor' => false],
        ]);
        $this->table->method('getAssignedPositions')->willReturn([
            10 => [100 => 0, 101 => 0, 102 => 3],
            11 => [200 => 1],
        ]);
        $this->table->method('getAssignedCategoryPaths')->willReturn([
            100 => ['1/2/10'],
            101 => ['1/2/10'],
            102 => ['1/2/10'],
            200 => ['1/2/10/11'],
        ]);
        $this->table->method('savePositions')->willReturnCallback(function (int $categoryId, array $positions): void {
            $this->saved[$categoryId] = $positions;
        });

        $this->storeScope = $this->createMock(StoreScope::class);
        $this->storeScope->method('getStores')->willReturn([$this->store(3, 'de', 2), $this->store(7, 'uk', 2)]);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturnCallback(fn (): array => $this->existingMerchandising);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $factory = $this->createMock(CategoryMerchandisingFactory::class);
        $factory->method('create')->willReturnCallback(fn (): CategoryMerchandising => $this->merchandising());
        $this->repository = $this->createMock(CategoryMerchandisingRepositoryInterface::class);
        $this->repository->method('save')->willReturnCallback(
            function (CategoryMerchandisingInterface $row): CategoryMerchandisingInterface {
                $this->savedMerchandising[] = $row;

                return $row;
            },
        );
        $this->sync = $this->createMock(CategoryMerchandisingSync::class);
        $this->report = new ImportReport();

        $this->sut = new CategoryPositionImporter(
            $this->reader,
            $this->table,
            new CategoryPositionPlanner(),
            $this->storeScope,
            $collectionFactory,
            $factory,
            $this->repository,
            $this->sync,
        );
    }

    public function test_global_positions_become_magento_positions_including_inherited_anchor_products(): void
    {
        $this->rows = [
            $this->row(10, 101, 0, 1),
            $this->row(10, 200, 0, 2),
        ];

        $this->sut->import($this->report, false, null);

        self::assertSame([10 => [101 => 1, 200 => 2, 100 => 3, 102 => 4]], $this->saved);
        $section = $this->report->getSections()[ImportReport::POSITIONS];
        self::assertSame([1, 1, 0], [$section['created'], $section['updated'], $section['unchanged']]);
        self::assertContains(
            '1 product(s) an anchor category only inherited from a subcategory were assigned to it to hold their position',
            $section['notes'],
        );
        self::assertContains(
            '2 assigned product(s) without an ElasticSuite position moved behind the positioned ones',
            $section['notes'],
        );
    }

    public function test_store_view_positions_are_reported_as_skipped(): void
    {
        $this->rows = [
            $this->row(10, 101, 7, 1),
            $this->row(11, 200, 3, 1),
        ];
        $this->storePositionCategories = [7 => [10]];

        $this->sut->import($this->report, false, null);

        self::assertSame([], $this->saved);
        self::assertSame(
            [
                'store view positions (Magento positions are global)' => ['category 10 product 101 store view 7'],
                'store view row ignored by ElasticSuite (use_store_positions is off)' => [
                    'category 11 product 200 store view 3',
                ],
            ],
            $this->report->getSections()[ImportReport::POSITIONS]['skipped'],
        );
    }

    public function test_positions_are_not_written_for_a_single_store_view(): void
    {
        $this->rows = [$this->row(10, 101, 0, 1)];
        $this->table->expects(self::never())->method('savePositions');

        $this->sut->import($this->report, false, 'de');

        self::assertSame(
            ['Magento category positions are global: run --positions without --store to import them'],
            $this->report->getSections()[ImportReport::POSITIONS]['notes'],
        );
    }

    public function test_blacklisted_products_are_hidden_in_every_store_view_of_the_category_and_synced(): void
    {
        $this->rows = [$this->row(10, 102, 0, null, true)];
        $synced = [];
        $this->sync->method('sync')->willReturnCallback(
            static function (int $categoryId, int $storeId, string $code) use (&$synced): void {
                $synced[] = sprintf('%d/%d/%s', $categoryId, $storeId, $code);
            },
        );

        $this->sut->import($this->report, false, null);

        self::assertSame(
            [[10, 3, 102, 'hide'], [10, 7, 102, 'hide']],
            array_map(
                static fn (CategoryMerchandisingInterface $row): array => [
                    $row->getCategoryId(),
                    $row->getStoreId(),
                    $row->getProductId(),
                    $row->getAction(),
                ],
                $this->savedMerchandising,
            ),
        );
        self::assertSame(['10/3/de', '10/7/uk'], $synced);
        self::assertSame(2, $this->report->getSections()[ImportReport::HIDES]['created']);
    }

    public function test_a_store_view_using_its_own_positions_takes_its_own_blacklist(): void
    {
        $this->rows = [
            $this->row(10, 102, 0, null, true),
            $this->row(10, 101, 7, null, true),
        ];
        $this->storePositionCategories = [7 => [10]];

        $this->sut->import($this->report, false, null);

        self::assertSame(
            [[3, 102], [7, 101]],
            array_map(
                static fn (CategoryMerchandisingInterface $row): array => [$row->getStoreId(), $row->getProductId()],
                $this->savedMerchandising,
            ),
        );
    }

    public function test_existing_hide_is_unchanged_and_a_pinned_product_is_not_hidden(): void
    {
        $this->rows = [$this->row(10, 102, 0, null, true)];
        $this->existingMerchandising = [
            $this->merchandising()->setCategoryId(10)->setStoreId(3)->setProductId(102)->setAction('hide'),
            $this->merchandising()->setCategoryId(10)->setStoreId(7)->setProductId(102)->setAction('pin'),
        ];
        $this->sync->expects(self::never())->method('sync');

        $this->sut->import($this->report, false, null);

        $section = $this->report->getSections()[ImportReport::HIDES];
        self::assertSame([0, 1], [$section['created'], $section['unchanged']]);
        self::assertSame(
            ['pinned in the category merchandiser' => ['category 10 product 102 store view 7']],
            $section['skipped'],
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->rows = [$this->row(10, 101, 0, 1), $this->row(10, 102, 0, null, true)];
        $this->table->expects(self::never())->method('savePositions');
        $this->repository->expects(self::never())->method('save');
        $this->sync->expects(self::never())->method('sync');

        $this->sut->import($this->report, true, null);

        self::assertSame(1, $this->report->getSections()[ImportReport::POSITIONS]['updated']);
        self::assertSame(2, $this->report->getSections()[ImportReport::HIDES]['created']);
    }

    public function test_missing_table_skips_the_import_with_a_note(): void
    {
        $reader = $this->createMock(ElasticSuiteReader::class);
        $reader->method('hasTables')->willReturn(false);
        $reader->expects(self::never())->method('getCategoryProductPositions');
        $importer = new CategoryPositionImporter(
            $reader,
            $this->table,
            new CategoryPositionPlanner(),
            $this->storeScope,
            $this->createMock(CollectionFactory::class),
            $this->createMock(CategoryMerchandisingFactory::class),
            $this->repository,
            $this->sync,
        );

        $importer->import($this->report, false, null);

        self::assertSame(
            ['ElasticSuite category position table not found, nothing to import'],
            $this->report->getSections()[ImportReport::POSITIONS]['notes'],
        );
    }

    /**
     * @return array{category_id: int, product_id: int, store_id: int, position: int|null, is_blacklisted: bool}
     */
    private function row(int $categoryId, int $productId, int $storeId, ?int $position, bool $blacklisted = false): array
    {
        return [
            'category_id' => $categoryId,
            'product_id' => $productId,
            'store_id' => $storeId,
            'position' => $position,
            'is_blacklisted' => $blacklisted,
        ];
    }

    private function store(int $id, string $code, int $rootCategoryId): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getRootCategoryId')->willReturn($rootCategoryId);

        return $store;
    }

    private function merchandising(): CategoryMerchandising
    {
        return $this->getMockBuilder(CategoryMerchandising::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }
}
