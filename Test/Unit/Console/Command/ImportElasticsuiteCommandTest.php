<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Console\Command\ImportElasticsuiteCommand;
use RunAsRoot\TypeSense\Model\ElasticSuite\BoostRuleImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryPositionImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\ImportReport;
use RunAsRoot\TypeSense\Model\ElasticSuite\StoreScope;
use RunAsRoot\TypeSense\Model\ElasticSuite\SynonymImporter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportElasticsuiteCommandTest extends TestCase
{
    private SynonymImporter&MockObject $synonymImporter;
    private BoostRuleImporter&MockObject $boostRuleImporter;
    private CategoryPositionImporter&MockObject $positionImporter;
    private StoreScope&MockObject $storeScope;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->synonymImporter = $this->createMock(SynonymImporter::class);
        $this->boostRuleImporter = $this->createMock(BoostRuleImporter::class);
        $this->positionImporter = $this->createMock(CategoryPositionImporter::class);
        $this->storeScope = $this->createMock(StoreScope::class);

        $this->tester = new CommandTester(new ImportElasticsuiteCommand(
            $this->synonymImporter,
            $this->boostRuleImporter,
            $this->positionImporter,
            $this->storeScope,
        ));
    }

    public function test_without_section_options_every_section_is_imported(): void
    {
        $this->synonymImporter->expects(self::once())->method('import')
            ->with(self::isInstanceOf(ImportReport::class), false, null);
        $this->boostRuleImporter->expects(self::once())->method('import');
        $this->positionImporter->expects(self::once())->method('import');

        self::assertSame(0, $this->tester->execute([]));
    }

    public function test_section_options_select_what_is_imported_and_options_are_passed_on(): void
    {
        $this->synonymImporter->expects(self::once())->method('import')
            ->with(self::isInstanceOf(ImportReport::class), true, 'de');
        $this->boostRuleImporter->expects(self::never())->method('import');
        $this->positionImporter->expects(self::never())->method('import');

        $this->tester->execute(['--synonyms' => true, '--dry-run' => true, '--store' => 'de']);

        self::assertStringContainsString('Dry run: nothing is written.', $this->tester->getDisplay());
    }

    public function test_summary_lists_counts_skip_reasons_and_notes(): void
    {
        $this->synonymImporter->method('import')->willReturnCallback(static function (ImportReport $report): void {
            $report->created(ImportReport::SYNONYMS, 2);
            $report->unchanged(ImportReport::SYNONYMS);
            $report->skipped(ImportReport::SYNONYMS, 'thesaurus #4 group 1', 'a single term');
            $report->skipped(ImportReport::SYNONYMS, 'thesaurus #4 group 2', 'a single term');
            $report->note(ImportReport::SYNONYMS, 'pushed later');
        });

        $this->tester->execute(['--synonyms' => true]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('synonyms: 2 created, 0 updated, 1 unchanged, 2 skipped', $display);
        self::assertStringContainsString(
            'skipped 2x: a single term (thesaurus #4 group 1, thesaurus #4 group 2)',
            $display,
        );
        self::assertStringContainsString('note: pushed later', $display);
    }

    public function test_long_skip_lists_are_shortened_unless_verbose(): void
    {
        $this->synonymImporter->method('import')->willReturnCallback(static function (ImportReport $report): void {
            $groups = range(1, 7);
            foreach ($groups as $group) {
                $report->skipped(ImportReport::SYNONYMS, 'group ' . $group, 'a single term');
            }
        });

        $this->tester->execute(['--synonyms' => true]);
        self::assertStringContainsString(
            '(group 1, group 2, group 3, group 4, group 5, +2 more)',
            $this->tester->getDisplay(),
        );

        $this->tester->execute(['--synonyms' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        self::assertStringContainsString('group 6, group 7)', $this->tester->getDisplay());
    }

    public function test_position_import_ends_with_the_reindex_hint(): void
    {
        $this->tester->execute(['--positions' => true]);

        self::assertStringContainsString('bin/magento typesense:reindex --entity=product', $this->tester->getDisplay());
    }

    public function test_unknown_store_view_fails_before_importing(): void
    {
        $this->storeScope->method('getStores')->with('xx')->willThrowException(
            new LocalizedException(__('Store view "xx" is not an active store view with Typesense enabled.')),
        );
        $this->synonymImporter->expects(self::never())->method('import');

        self::assertSame(1, $this->tester->execute(['--store' => 'xx']));
        self::assertStringContainsString('Store view "xx" is not an active store view', $this->tester->getDisplay());
    }

    public function test_a_failing_section_is_reported_and_the_others_still_run(): void
    {
        $this->synonymImporter->method('import')->willThrowException(new \RuntimeException('Connection refused'));
        $this->boostRuleImporter->expects(self::once())->method('import');

        self::assertSame(1, $this->tester->execute(['--synonyms' => true, '--boosts' => true]));
        self::assertStringContainsString('synonyms failed: Connection refused', $this->tester->getDisplay());
    }
}
