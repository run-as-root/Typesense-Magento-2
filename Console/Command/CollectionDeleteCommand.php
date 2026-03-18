<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Framework\Console\Cli;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CollectionDeleteCommand extends Command
{
    private const ARGUMENT_NAME = 'name';
    private const OPTION_FORCE = 'force';

    public function __construct(
        private readonly CollectionManagerInterface $collectionManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:collection:delete')
            ->setDescription('Delete a Typesense collection by name')
            ->addArgument(
                self::ARGUMENT_NAME,
                InputArgument::REQUIRED,
                'The name of the collection to delete',
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
}
