<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Store\Api\StoreRepositoryInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Collection\ZeroDowntimeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CollectionRollbackCommand extends Command
{
    private const OPTION_ENTITY = 'entity';
    private const OPTION_STORE = 'store';

    public function __construct(
        private readonly ZeroDowntimeService $zeroDowntimeService,
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly StoreRepositoryInterface $storeRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:collection:rollback')
            ->setDescription('Point the collection alias back at the previous version')
            ->addOption(self::OPTION_ENTITY, null, InputOption::VALUE_REQUIRED, 'Entity type (e.g. product)')
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_REQUIRED, 'Store code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityType = (string) $input->getOption(self::OPTION_ENTITY);
        $storeCode = (string) $input->getOption(self::OPTION_STORE);

        if ($entityType === '' || $storeCode === '') {
            $output->writeln('<error>Both --entity and --store are required.</error>');

            return Command::FAILURE;
        }

        try {
            $storeId = (int) $this->storeRepository->get($storeCode)->getId();
            $collection = $this->zeroDowntimeService->rollback($entityType, $storeCode, $storeId);
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $alias = $this->nameResolver->resolve($entityType, $storeCode, $storeId);
        $output->writeln(sprintf('<info>Alias %s now points to %s</info>', $alias, $collection));

        return Command::SUCCESS;
    }
}
