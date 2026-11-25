<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Framework\Console\Cli;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Model\Collection\EntityCollectionPurger;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;
use RunAsRoot\TypeSense\Model\Indexer\IndexableStoreResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CollectionDeleteCommand extends Command
{
    private const ARGUMENT_NAME = 'name';
    private const OPTION_FORCE = 'force';
    private const OPTION_ENTITY = 'entity';

    public function __construct(
        private readonly CollectionManagerInterface $collectionManager,
        private readonly EntityCollectionPurger $purger,
        private readonly IndexableStoreResolver $storeResolver,
        private readonly EntityIndexerPool $indexerPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:collection:delete')
            ->setDescription('Delete a Typesense collection by name, or every collection of a disabled entity type')
            ->addArgument(
                self::ARGUMENT_NAME,
                InputArgument::OPTIONAL,
                'The name of the collection to delete',
            )
            ->addOption(
                self::OPTION_ENTITY,
                null,
                InputOption::VALUE_REQUIRED,
                'Delete the aliases and collections of this entity type in every store view; its indexing must be disabled',
            )
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Force deletion without confirmation warning',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument(self::ARGUMENT_NAME);
        $force = (bool) $input->getOption(self::OPTION_FORCE);
        $entityType = (string) $input->getOption(self::OPTION_ENTITY);

        if ($entityType !== '') {
            return $this->purgeEntityType($entityType, $force, $output);
        }

        if ($name === '') {
            $output->writeln('<error>Pass a collection name or --entity=<entity type>.</error>');

            return Cli::RETURN_FAILURE;
        }

        if (!$force) {
            $output->writeln(sprintf(
                '<comment>WARNING: You are about to delete collection "%s". Use --force to confirm.</comment>',
                $name,
            ));

            return Cli::RETURN_FAILURE;
        }

        try {
            $this->collectionManager->deleteCollection($name);
            $output->writeln(sprintf('<info>Collection "%s" deleted successfully.</info>', $name));
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            $output->writeln(sprintf('<error>Collection "%s" not found.</error>', $name));

            return Cli::RETURN_FAILURE;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Failed to delete collection "%s": %s</error>', $name, $e->getMessage()));

            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    private function purgeEntityType(string $entityType, bool $force, OutputInterface $output): int
    {
        if (!$this->indexerPool->hasIndexer($entityType)) {
            $output->writeln(sprintf('<error>Unknown entity type "%s".</error>', $entityType));

            return Cli::RETURN_FAILURE;
        }

        if (!$force) {
            $output->writeln(sprintf(
                '<comment>WARNING: You are about to delete every %s collection. Use --force to confirm.</comment>',
                $entityType,
            ));

            return Cli::RETURN_FAILURE;
        }

        // The next reindex or entity save would recreate the collections.
        if ($this->storeResolver->getStores($entityType) !== []) {
            $output->writeln(sprintf(
                '<error>%1$s is still indexed in at least one store view. Disable'
                . ' run_as_root_typesense/indexing/%1$s_enabled first.</error>',
                $entityType,
            ));

            return Cli::RETURN_FAILURE;
        }

        try {
            $deleted = $this->purger->purge($entityType);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Failed to delete the %s collections: %s</error>', $entityType, $e->getMessage()));

            return Cli::RETURN_FAILURE;
        }

        foreach ($deleted as $deletedName) {
            $output->writeln(sprintf('<info>Deleted %s</info>', $deletedName));
        }
        $output->writeln(sprintf('<info>%d %s aliases and collections deleted.</info>', count($deleted), $entityType));

        return Cli::RETURN_SUCCESS;
    }
}
