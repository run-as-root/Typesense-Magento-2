<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Framework\Console\Cli;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CollectionListCommand extends Command
{
    public function __construct(
        private readonly CollectionManagerInterface $collectionManager,
        private readonly AliasManagerInterface $aliasManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:collection:list')
            ->setDescription('List all Typesense collections with document count and alias mappings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $collections = $this->collectionManager->listCollections();
            $aliases = $this->aliasManager->listAliases();

            $aliasMap = $this->buildAliasMap($aliases);

            $table = new Table($output);
            $table->setHeaders(['Name', 'Documents', 'Alias']);

            foreach ($collections as $collection) {
                $name = $collection['name'] ?? '';
                $numDocuments = (string) ($collection['num_documents'] ?? 0);
                $alias = $aliasMap[$name] ?? '';

                $table->addRow([$name, $numDocuments, $alias]);
            }

            $table->render();
            $output->writeln(sprintf('<info>Total collections: %d</info>', count($collections)));
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Failed to list collections: %s</error>', $e->getMessage()));

            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Build a reverse map from collection name to alias name.
     *
     * @param array<int, array{name: string, collection_name: string}> $aliases
     * @return array<string, string>
     */
    private function buildAliasMap(array $aliases): array
    {
        $map = [];

        foreach ($aliases as $alias) {
            $collectionName = $alias['collection_name'] ?? '';
            $aliasName = $alias['name'] ?? '';

            if ($collectionName !== '' && $aliasName !== '') {
                $map[$collectionName] = $aliasName;
            }
        }

        return $map;
    }
}
