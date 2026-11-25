<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use RunAsRoot\TypeSense\Model\ElasticSuite\BoostRuleImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryFilterImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryPositionImporter;
use RunAsRoot\TypeSense\Model\ElasticSuite\ImportReport;
use RunAsRoot\TypeSense\Model\ElasticSuite\StoreScope;
use RunAsRoot\TypeSense\Model\ElasticSuite\SynonymImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportElasticsuiteCommand extends Command
{
    private const string OPTION_SYNONYMS = 'synonyms';
    private const string OPTION_BOOSTS = 'boosts';
    private const string OPTION_POSITIONS = 'positions';
    private const string OPTION_FILTERS = 'filters';
    private const string OPTION_DRY_RUN = 'dry-run';
    private const string OPTION_STORE = 'store';
    private const int SAMPLE_SIZE = 5;

    public function __construct(
        private readonly SynonymImporter $synonymImporter,
        private readonly BoostRuleImporter $boostRuleImporter,
        private readonly CategoryPositionImporter $positionImporter,
        private readonly StoreScope $storeScope,
        private readonly CategoryFilterImporter $filterImporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:import:elasticsuite')
            ->setDescription(
                'Import ElasticSuite thesauri, optimizers, category positions and category filter settings'
                . ' (all without options)',
            )
            ->addOption(self::OPTION_SYNONYMS, null, InputOption::VALUE_NONE, 'Thesauri as synonyms')
            ->addOption(self::OPTION_BOOSTS, null, InputOption::VALUE_NONE, 'Optimizers as boosting rules')
            ->addOption(
                self::OPTION_POSITIONS,
                null,
                InputOption::VALUE_NONE,
                'Merchandiser positions as Magento category positions, blacklisted products as hidden products',
            )
            ->addOption(
                self::OPTION_FILTERS,
                null,
                InputOption::VALUE_NONE,
                'Per-category filter settings (always hidden / always displayed) as TypeSense category filter settings',
            )
            ->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Report what would change, write nothing')
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_REQUIRED, 'Only this store view (code)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $storeCode = $input->getOption(self::OPTION_STORE);
        $storeCode = is_string($storeCode) && $storeCode !== '' ? $storeCode : null;

        if ($storeCode !== null) {
            try {
                $this->storeScope->getStores($storeCode);
            } catch (LocalizedException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        $sections = $this->getSections($input);
        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing is written.</comment>');
        }

        $report = new ImportReport();
        $failed = false;
        foreach ($sections as $label => $importer) {
            try {
                $importer->import($report, $dryRun, $storeCode);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s failed: %s</error>', $label, $e->getMessage()));
                $failed = true;
            }
        }

        $this->printReport($report, $output);

        if (isset($sections[ImportReport::POSITIONS]) && !$dryRun) {
            $output->writeln(
                'Run a full product reindex (bin/magento typesense:reindex --entity=product) so category listings'
                . ' sort by the imported positions.',
            );
        }
        if (isset($sections[ImportReport::FILTERS]) && !$dryRun) {
            $output->writeln('Clean the full page cache so cached category pages pick up the imported filter settings.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<string, SynonymImporter|BoostRuleImporter|CategoryPositionImporter|CategoryFilterImporter>
     */
    private function getSections(InputInterface $input): array
    {
        $all = [
            ImportReport::SYNONYMS => [self::OPTION_SYNONYMS, $this->synonymImporter],
            ImportReport::BOOSTS => [self::OPTION_BOOSTS, $this->boostRuleImporter],
            ImportReport::POSITIONS => [self::OPTION_POSITIONS, $this->positionImporter],
            ImportReport::FILTERS => [self::OPTION_FILTERS, $this->filterImporter],
        ];
        $selected = array_filter($all, static fn (array $section): bool => (bool) $input->getOption($section[0]));

        return array_map(
            static fn (array $section): SynonymImporter|BoostRuleImporter|CategoryPositionImporter|CategoryFilterImporter
                => $section[1],
            $selected === [] ? $all : $selected,
        );
    }

    private function printReport(ImportReport $report, OutputInterface $output): void
    {
        $sections = $report->getSections();
        foreach ($sections as $name => $section) {
            $skippedCount = array_sum(array_map('count', $section['skipped']));
            $output->writeln(sprintf(
                '<info>%s: %d created, %d updated, %d unchanged, %d skipped</info>',
                $name,
                $section['created'],
                $section['updated'],
                $section['unchanged'],
                $skippedCount,
            ));

            foreach ($section['skipped'] as $reason => $subjects) {
                $output->writeln(sprintf(
                    '  skipped %dx: %s (%s)',
                    count($subjects),
                    $reason,
                    $this->formatSubjects($subjects, $output->isVerbose()),
                ));
            }
            foreach ($section['notes'] as $note) {
                $output->writeln('  note: ' . $note);
            }
        }
    }

    /**
     * @param list<string> $subjects
     */
    private function formatSubjects(array $subjects, bool $all): string
    {
        if ($all || count($subjects) <= self::SAMPLE_SIZE) {
            return implode(', ', $subjects);
        }

        return sprintf(
            '%s, +%d more',
            implode(', ', array_slice($subjects, 0, self::SAMPLE_SIZE)),
            count($subjects) - self::SAMPLE_SIZE,
        );
    }
}
