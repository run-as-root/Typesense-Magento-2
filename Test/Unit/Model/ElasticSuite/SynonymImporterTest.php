<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\ElasticSuite;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\ElasticSuite\ElasticSuiteReader;
use RunAsRoot\TypeSense\Model\ElasticSuite\ImportReport;
use RunAsRoot\TypeSense\Model\ElasticSuite\StoreScope;
use RunAsRoot\TypeSense\Model\ElasticSuite\SynonymImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\ThesaurusMapper;
use RunAsRoot\TypeSense\Model\Synonym\Synonym;
use RunAsRoot\TypeSense\Model\Synonym\SynonymFactory;
use RunAsRoot\TypeSense\Model\Synonym\SynonymPusher;

final class SynonymImporterTest extends TestCase
{
    private ElasticSuiteReader&MockObject $reader;
    private StoreScope&MockObject $storeScope;
    private TypeSenseConfigInterface&MockObject $config;
    private SynonymRepositoryInterface&MockObject $repository;
    private SynonymFactory&MockObject $factory;
    private SynonymPusher&MockObject $pusher;
    /** @var array<string, SynonymInterface> existing synonyms by "<collection>|<typesense id>" */
    private array $existing = [];
    /** @var list<SynonymInterface> */
    private array $saved = [];
    private ImportReport $report;
    private SynonymImporter $sut;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ElasticSuiteReader::class);
        $this->reader->method('hasTables')->willReturn(true);
        $this->reader->method('getThesauri')->willReturn([
            ['thesaurus_id' => 3, 'name' => 'Zwetschke', 'type' => 'expansion', 'is_active' => true],
        ]);
        $this->reader->method('getThesaurusStoreIds')->willReturn([3 => [0]]);
        $this->reader->method('getExpandedTerms')->willReturn([
            ['thesaurus_id' => 3, 'term_id' => 1, 'term' => 'zwetschge'],
        ]);
        $this->reader->method('getReferenceTerms')->willReturn([
            ['thesaurus_id' => 3, 'term_id' => 1, 'term' => 'zwetschke'],
        ]);

        $this->storeScope = $this->createMock(StoreScope::class);
        $this->storeScope->method('getStores')->willReturn([$this->store(2, 'at'), $this->store(3, 'de')]);
        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entity, string $code): string => sprintf('rar_%s_%s', $entity, $code),
        );
        $this->config = $this->createMock(TypeSenseConfigInterface::class);

        $this->repository = $this->createMock(SynonymRepositoryInterface::class);
        $this->repository->method('getByTypesenseId')->willReturnCallback(
            function (string $collection, string $typesenseId): SynonymInterface {
                return $this->existing[$collection . '|' . $typesenseId]
                    ?? throw new NoSuchEntityException(__('missing'));
            },
        );
        $this->repository->method('save')->willReturnCallback(function (SynonymInterface $synonym): SynonymInterface {
            $this->saved[] = $synonym;

            return $synonym;
        });
        $this->factory = $this->createMock(SynonymFactory::class);
        $this->factory->method('create')->willReturnCallback(fn (): Synonym => $this->synonym());
        $this->pusher = $this->createMock(SynonymPusher::class);
        $this->report = new ImportReport();

        $this->sut = new SynonymImporter(
            $this->reader,
            new ThesaurusMapper(),
            $this->storeScope,
            $nameResolver,
            $this->config,
            $this->repository,
            $this->factory,
            $this->pusher,
        );
    }

    public function test_creates_and_pushes_the_synonym_for_the_product_collection_of_every_store_view(): void
    {
        $pushed = [];
        $this->pusher->method('push')->willReturnCallback(
            static function (SynonymInterface $synonym, string $collection) use (&$pushed): void {
                $pushed[] = $collection . ':' . $synonym->getTypesenseId();
            },
        );

        $this->sut->import($this->report, false, null);

        self::assertSame(
            ['rar_product_at:es-thesaurus-3-1', 'rar_product_de:es-thesaurus-3-1'],
            $pushed,
        );
        self::assertCount(2, $this->saved);
        self::assertSame('rar_product_at', $this->saved[0]->getCollectionName());
        self::assertSame(SynonymInterface::TYPE_ONE_WAY, $this->saved[0]->getType());
        self::assertSame('zwetschke', $this->saved[0]->getRoot());
        self::assertSame(['zwetschge'], $this->saved[0]->getSynonyms());
        self::assertSame(2, $this->report->getSections()[ImportReport::SYNONYMS]['created']);
    }

    public function test_category_collections_get_the_synonyms_where_categories_are_indexed(): void
    {
        $this->config->method('isCategoryIndexingEnabled')->willReturnMap([[2, true], [3, false]]);

        $this->sut->import($this->report, false, null);

        self::assertSame(
            ['rar_product_at', 'rar_category_at', 'rar_product_de'],
            array_map(static fn (SynonymInterface $synonym): string => $synonym->getCollectionName(), $this->saved),
        );
    }

    public function test_identical_existing_synonym_is_unchanged_and_a_changed_one_is_updated(): void
    {
        $this->existing['rar_product_at|es-thesaurus-3-1'] = $this->synonym(1)
            ->setCollectionName('rar_product_at')->setTypesenseId('es-thesaurus-3-1')
            ->setType(SynonymInterface::TYPE_ONE_WAY)->setRoot('zwetschke')->setSynonyms(['zwetschge']);
        $this->existing['rar_product_de|es-thesaurus-3-1'] = $this->synonym(2)
            ->setCollectionName('rar_product_de')->setTypesenseId('es-thesaurus-3-1')
            ->setType(SynonymInterface::TYPE_ONE_WAY)->setRoot('zwetschke')->setSynonyms(['old']);
        $this->pusher->expects(self::exactly(2))->method('push');

        $this->sut->import($this->report, false, null);

        $section = $this->report->getSections()[ImportReport::SYNONYMS];
        self::assertSame([0, 1, 1], [$section['created'], $section['updated'], $section['unchanged']]);
        self::assertCount(1, $this->saved);
        self::assertSame(['zwetschge'], $this->saved[0]->getSynonyms());
    }

    public function test_dry_run_neither_saves_nor_pushes(): void
    {
        $this->repository->expects(self::never())->method('save');
        $this->pusher->expects(self::never())->method('push');

        $this->sut->import($this->report, true, null);

        self::assertSame(2, $this->report->getSections()[ImportReport::SYNONYMS]['created']);
    }

    public function test_store_specific_thesaurus_outside_the_scope_is_skipped(): void
    {
        $reader = $this->createMock(ElasticSuiteReader::class);
        $reader->method('hasTables')->willReturn(true);
        $reader->method('getThesauri')->willReturn([
            ['thesaurus_id' => 2, 'name' => 'Birnerla', 'type' => 'expansion', 'is_active' => true],
        ]);
        $reader->method('getThesaurusStoreIds')->willReturn([2 => [7]]);
        $reader->method('getExpandedTerms')->willReturn([['thesaurus_id' => 2, 'term_id' => 1, 'term' => 'birnerla']]);
        $reader->method('getReferenceTerms')->willReturn([['thesaurus_id' => 2, 'term_id' => 1, 'term' => 'williams']]);
        $this->repository->expects(self::never())->method('save');

        $this->importerWith($reader)->import($this->report, false, null);

        self::assertSame(
            ['store view(s) 7 not in scope' => ['thesaurus #2 "Birnerla" group 1']],
            $this->report->getSections()[ImportReport::SYNONYMS]['skipped'],
        );
    }

    public function test_push_failure_is_reported_and_the_synonym_stays_saved(): void
    {
        $this->pusher->method('push')->willThrowException(new \RuntimeException('Not found'));

        $this->sut->import($this->report, false, null);

        self::assertCount(2, $this->saved);
        self::assertContains(
            'es-thesaurus-3-1 saved for rar_product_at but not pushed (Not found); it is applied with the next reindex',
            $this->report->getSections()[ImportReport::SYNONYMS]['notes'],
        );
    }

    public function test_missing_tables_skip_the_import_with_a_note(): void
    {
        $reader = $this->createMock(ElasticSuiteReader::class);
        $reader->method('hasTables')->willReturn(false);
        $reader->expects(self::never())->method('getThesauri');

        $this->importerWith($reader)->import($this->report, false, null);

        self::assertSame(
            ['ElasticSuite thesaurus tables not found, nothing to import'],
            $this->report->getSections()[ImportReport::SYNONYMS]['notes'],
        );
    }

    private function importerWith(ElasticSuiteReader $reader): SynonymImporter
    {
        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entity, string $code): string => sprintf('rar_%s_%s', $entity, $code),
        );

        return new SynonymImporter(
            $reader,
            new ThesaurusMapper(),
            $this->storeScope,
            $nameResolver,
            $this->config,
            $this->repository,
            $this->factory,
            $this->pusher,
        );
    }

    private function store(int $id, string $code): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);

        return $store;
    }

    private function synonym(?int $id = null): Synonym
    {
        $synonym = $this->getMockBuilder(Synonym::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $synonym->method('getId')->willReturn($id);

        return $synonym;
    }
}
