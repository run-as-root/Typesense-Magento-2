<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\ElasticSuite;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;
use RunAsRoot\TypeSense\Model\Boost\BoostRule;
use RunAsRoot\TypeSense\Model\Boost\BoostRuleFactory;
use RunAsRoot\TypeSense\Model\ElasticSuite\BoostRuleImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\ElasticSuiteReader;
use RunAsRoot\TypeSense\Model\ElasticSuite\ImportReport;
use RunAsRoot\TypeSense\Model\ElasticSuite\OptimizerConversion;
use RunAsRoot\TypeSense\Model\ElasticSuite\OptimizerConverter;
use RunAsRoot\TypeSense\Model\ElasticSuite\StoreScope;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\Collection;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\CollectionFactory;

final class BoostRuleImporterTest extends TestCase
{
    private const array CONDITIONS = ['type' => 'combine', 'aggregator' => 'all', 'conditions' => []];

    private ElasticSuiteReader&MockObject $reader;
    private OptimizerConverter&MockObject $converter;
    private StoreScope&MockObject $storeScope;
    private BoostRuleRepositoryInterface&MockObject $repository;
    private TypeListInterface&MockObject $cacheTypeList;
    /** @var array<string, BoostRule> existing rules by name filter */
    private array $existing = [];
    /** @var list<BoostRuleInterface> */
    private array $saved = [];
    private ImportReport $report;
    private BoostRuleImporter $sut;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ElasticSuiteReader::class);
        $this->reader->method('hasTables')->willReturn(true);
        $this->reader->method('getOptimizers')->willReturn([$this->optimizer()]);
        $this->reader->method('getOptimizerContainers')->willReturn([1 => ['quick_search_container' => 1]]);
        $this->reader->method('getOptimizerLimitations')->willReturn([
            1 => ['query_count' => 2, 'queries' => [], 'category_count' => 0],
        ]);
        $this->converter = $this->createMock(OptimizerConverter::class);
        $this->converter->method('convert')->willReturn(
            OptimizerConversion::converted(1000, true, false, self::CONDITIONS, null),
        );
        $this->storeScope = $this->createMock(StoreScope::class);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(3);
        $this->storeScope->method('getStores')->willReturn([$store]);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(function (): Collection {
            $filter = '';
            $collection = $this->createMock(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function (string $field, array $condition) use (&$filter, &$collection): Collection {
                    $filter = $field . ' like ' . $condition['like'];

                    return $collection;
                },
            );
            $collection->method('setPageSize')->willReturnSelf();
            $collection->method('getFirstItem')->willReturnCallback(
                function () use (&$filter): BoostRule {
                    return $this->existing[$filter] ?? $this->rule();
                },
            );

            return $collection;
        });
        $factory = $this->createMock(BoostRuleFactory::class);
        $factory->method('create')->willReturnCallback(fn (): BoostRule => $this->rule());
        $this->repository = $this->createMock(BoostRuleRepositoryInterface::class);
        $this->repository->method('save')->willReturnCallback(function (BoostRuleInterface $rule): BoostRuleInterface {
            $this->saved[] = $rule;

            return $rule;
        });
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->report = new ImportReport();

        $this->sut = new BoostRuleImporter(
            $this->reader,
            $this->converter,
            $this->storeScope,
            $collectionFactory,
            $factory,
            $this->repository,
            $this->cacheTypeList,
        );
    }

    public function test_creates_a_boosting_rule_named_after_the_optimizer(): void
    {
        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('full_page');

        $this->sut->import($this->report, false, null);

        self::assertCount(1, $this->saved);
        $rule = $this->saved[0];
        self::assertSame('ES optimizer #1: Birnerla', $rule->getName());
        self::assertSame(3, $rule->getStoreId());
        self::assertSame(1000, $rule->getWeight());
        self::assertTrue($rule->isApplyToSearch());
        self::assertFalse($rule->isApplyToCategory());
        self::assertSame('2026-01-01', $rule->getFromDate());
        self::assertNull($rule->getToDate());
        self::assertSame(self::CONDITIONS, $rule->getConditions());
        self::assertTrue($rule->isActive());
        self::assertSame(1, $this->report->getSections()[ImportReport::BOOSTS]['created']);
    }

    public function test_query_limited_optimizer_is_created_inactive_with_a_note(): void
    {
        $converter = $this->createMock(OptimizerConverter::class);
        $converter->method('convert')->willReturn(
            OptimizerConversion::converted(1000, true, false, self::CONDITIONS, 'limited to 2 search queries'),
        );

        $this->importerWith($converter)->import($this->report, false, null);

        self::assertFalse($this->saved[0]->isActive());
        self::assertSame(
            ['optimizer #1 "Birnerla" imported inactive: limited to 2 search queries'],
            $this->report->getSections()[ImportReport::BOOSTS]['notes'],
        );
    }

    public function test_conversion_notes_are_reported(): void
    {
        $converter = $this->createMock(OptimizerConverter::class);
        $converter->method('convert')->willReturn(
            OptimizerConversion::converted(1000, true, false, self::CONDITIONS, null, ['sku contains "a" became …']),
        );

        $this->importerWith($converter)->import($this->report, true, null);

        self::assertSame(
            ['optimizer #1 "Birnerla": sku contains "a" became …'],
            $this->report->getSections()[ImportReport::BOOSTS]['notes'],
        );
    }

    public function test_rerun_updates_the_existing_rule_and_keeps_its_active_state(): void
    {
        $existing = $this->rule(5)->setName('ES optimizer #1: Birnerla')->setStoreId(3)->setIsActive(false)
            ->setWeight(10)->setApplyToSearch(true)->setApplyToCategory(false)->setFromDate('2026-01-01')
            ->setConditionsSerialized(json_encode(self::CONDITIONS, JSON_THROW_ON_ERROR));
        $this->existing['name like ES optimizer #1:%'] = $existing;

        $this->sut->import($this->report, false, null);

        self::assertSame([$existing], $this->saved);
        self::assertSame(1000, $existing->getWeight());
        self::assertFalse($existing->isActive());
        self::assertSame(1, $this->report->getSections()[ImportReport::BOOSTS]['updated']);
    }

    public function test_rerun_without_changes_saves_nothing(): void
    {
        $this->existing['name like ES optimizer #1:%'] = $this->rule(5)->setName('ES optimizer #1: Birnerla')
            ->setStoreId(3)->setIsActive(true)->setWeight(1000)->setApplyToSearch(true)
            ->setApplyToCategory(false)->setFromDate('2026-01-01')->setToDate(null)
            ->setConditionsSerialized(json_encode(self::CONDITIONS, JSON_THROW_ON_ERROR));
        $this->cacheTypeList->expects(self::never())->method('cleanType');

        $this->sut->import($this->report, false, null);

        self::assertSame([], $this->saved);
        self::assertSame(1, $this->report->getSections()[ImportReport::BOOSTS]['unchanged']);
    }

    public function test_unconvertible_optimizer_is_skipped_with_the_converter_reason(): void
    {
        $converter = $this->createMock(OptimizerConverter::class);
        $converter->method('convert')->willReturn(OptimizerConversion::skipped('model "attribute_value" is not supported'));

        $this->importerWith($converter)->import($this->report, false, null);

        self::assertSame([], $this->saved);
        self::assertSame(
            ['model "attribute_value" is not supported' => ['optimizer #1 "Birnerla"']],
            $this->report->getSections()[ImportReport::BOOSTS]['skipped'],
        );
    }

    public function test_inactive_optimizer_and_optimizer_of_a_store_out_of_scope_are_skipped(): void
    {
        $reader = $this->createMock(ElasticSuiteReader::class);
        $reader->method('hasTables')->willReturn(true);
        $reader->method('getOptimizers')->willReturn([
            ['is_active' => '0'] + $this->optimizer(),
            ['optimizer_id' => '2', 'store_id' => '9'] + $this->optimizer(),
        ]);
        $reader->method('getOptimizerContainers')->willReturn([]);
        $reader->method('getOptimizerLimitations')->willReturn([]);

        $this->importerWith($this->converter, $reader)->import($this->report, false, null);

        self::assertSame(
            [
                'inactive' => ['optimizer #1 "Birnerla"'],
                'store view 9 not in scope' => ['optimizer #2 "Birnerla"'],
            ],
            $this->report->getSections()[ImportReport::BOOSTS]['skipped'],
        );
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->repository->expects(self::never())->method('save');
        $this->cacheTypeList->expects(self::never())->method('cleanType');

        $this->sut->import($this->report, true, null);

        self::assertSame(1, $this->report->getSections()[ImportReport::BOOSTS]['created']);
    }

    public function test_missing_tables_skip_the_import_with_a_note(): void
    {
        $reader = $this->createMock(ElasticSuiteReader::class);
        $reader->method('hasTables')->willReturn(false);

        $this->importerWith($this->converter, $reader)->import($this->report, false, null);

        self::assertSame(
            ['ElasticSuite optimizer tables not found, nothing to import'],
            $this->report->getSections()[ImportReport::BOOSTS]['notes'],
        );
    }

    private function importerWith(OptimizerConverter $converter, ?ElasticSuiteReader $reader = null): BoostRuleImporter
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturnCallback(fn (): BoostRule => $this->rule());
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $factory = $this->createMock(BoostRuleFactory::class);
        $factory->method('create')->willReturnCallback(fn (): BoostRule => $this->rule());

        return new BoostRuleImporter(
            $reader ?? $this->reader,
            $converter,
            $this->storeScope,
            $collectionFactory,
            $factory,
            $this->repository,
            $this->cacheTypeList,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function optimizer(): array
    {
        return [
            'optimizer_id' => '1',
            'store_id' => '3',
            'is_active' => '1',
            'from_date' => '2026-01-01',
            'to_date' => null,
            'name' => 'Birnerla',
            'model' => 'constant_score',
            'config' => '{"constant_score_value":"1000"}',
            'rule_condition' => '{}',
        ];
    }

    private function rule(?int $id = null): BoostRule
    {
        $rule = $this->getMockBuilder(BoostRule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $rule->method('getId')->willReturn($id);

        return $rule;
    }
}
