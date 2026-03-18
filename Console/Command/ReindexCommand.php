<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Framework\Console\Cli;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReindexCommand extends Command
{
    private const OPTION_ENTITY = 'entity';
    private const OPTION_STORE = 'store';

    public function __construct(
        private readonly IndexerOrchestratorInterface $orchestrator,
        private readonly EntityIndexerPool $indexerPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:reindex')
            ->setDescription('Reindex entities into Typesense')
            ->addOption(
                self::OPTION_ENTITY,
                null,
                InputOption::VALUE_OPTIONAL,
                'Entity type to reindex (product, category, cms_page, suggestion). If omitted, reindexes all.',
            )
            ->addOption(
                self::OPTION_STORE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Store code to reindex. If omitted, reindexes all stores.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityType = $input->getOption(self::OPTION_ENTITY);

        if ($entityType !== null && !$this->indexerPool->hasIndexer($entityType)) {
            $output->writeln(sprintf(
                '<error>Unknown entity type "%s". Valid types: %s</error>',
                $entityType,
                implode(', ', array_keys($this->indexerPool->getAll())),
            ));

            return Cli::RETURN_FAILURE;
        }

        try {
            if ($entityType === null) {
                $output->writeln('<info>Reindexing all entity types...</info>');
                $this->orchestrator->reindexAll();
                $output->writeln('<info>Reindex complete.</info>');
            } else {
                $output->writeln(sprintf('<info>Reindexing entity type: %s</info>', $entityType));
                $this->orchestrator->reindex($entityType);
                $output->writeln(sprintf('<info>Reindex complete for: %s</info>', $entityType));
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Reindex failed: %s</error>', $e->getMessage()));

            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }
}
