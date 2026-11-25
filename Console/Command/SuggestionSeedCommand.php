<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionCollectionProvisioner;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionPruner;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionSeeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SuggestionSeedCommand extends Command
{
    private const OPTION_LIMIT = 'limit';
    private const OPTION_STORE = 'store';

    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly TypeSenseConfigInterface $config,
        private readonly SuggestionCollectionProvisioner $provisioner,
        private readonly SuggestionSeeder $seeder,
        private readonly SuggestionPruner $pruner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:suggestions:seed')
            ->setDescription(
                'Import the most popular Magento search terms into the Typesense suggestion collections, then '
                . 'prune them. Terms already present keep their count, so it is safe to run again.',
            )
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Terms per store (default and maximum: the configured suggestion limit)',
            )
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_REQUIRED, 'Store code (default: all stores)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limitOption = $input->getOption(self::OPTION_LIMIT);
        if ($limitOption !== null && !preg_match('/^[1-9]\d*$/', (string) $limitOption)) {
            $output->writeln('<error>--limit must be a positive integer.</error>');

            return Command::FAILURE;
        }
        $limit = $limitOption === null ? null : (int) $limitOption;

        $storeCode = $input->getOption(self::OPTION_STORE);
        try {
            $stores = $storeCode === null ? $this->storeRepository->getList() : [$this->storeRepository->get($storeCode)];
        } catch (NoSuchEntityException) {
            $output->writeln(sprintf('<error>Unknown store "%s".</error>', $storeCode));

            return Command::FAILURE;
        }

        $result = Command::SUCCESS;
        foreach ($stores as $store) {
            if (!$this->seedStore($store, $limit, $output)) {
                $result = Command::FAILURE;
            }
        }

        return $result;
    }

    private function seedStore(StoreInterface $store, ?int $limit, OutputInterface $output): bool
    {
        $storeId = (int) $store->getId();
        $code = (string) $store->getCode();
        if ($code === 'admin' || !$store->getIsActive() || !$this->config->isEnabled($storeId)) {
            return true;
        }
        if (!$this->config->isSuggestionIndexingEnabled($storeId)) {
            $output->writeln(sprintf('%s: suggestions disabled, skipped', $code));

            return true;
        }

        try {
            $this->provisioner->ensure($storeId, $code);
            $stats = $this->seeder->seed($storeId, $code, $limit);
            $pruned = $this->pruner->prune($storeId, $code);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s: %s</error>', $code, $e->getMessage()));

            return false;
        }

        $output->writeln(sprintf(
            '%s: %d imported, %d already present, %d failed; pruned: %d shown, %d without results, %d deleted',
            $code,
            $stats['imported'],
            $stats['existing'],
            $stats['failed'],
            $pruned['valid'],
            $pruned['invalid'],
            $pruned['deleted'],
        ));

        return $stats['failed'] === 0;
    }
}
