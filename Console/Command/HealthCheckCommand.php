<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Console\Command;

use Magento\Framework\Console\Cli;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HealthCheckCommand extends Command
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly TypeSenseConfigInterface $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('typesense:health')
            ->setDescription('Check the health status of the Typesense server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $this->config->getHost();
        $port = $this->config->getPort();
        $protocol = $this->config->getProtocol();

        $output->writeln('<info>Typesense Connection Details:</info>');
        $output->writeln(sprintf('  Host:     %s', $host));
        $output->writeln(sprintf('  Port:     %d', $port));
        $output->writeln(sprintf('  Protocol: %s', $protocol));
        $output->writeln('');

        try {
            $client = $this->clientFactory->create();
            $result = $client->health->retrieve();

            $isOk = $result['ok'] ?? false;

            if ($isOk) {
                $output->writeln('<info>Status: OK</info>');
            } else {
                $output->writeln('<comment>Status: Unhealthy (server returned ok=false)</comment>');

                return Cli::RETURN_FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Status: FAILED — %s</error>', $e->getMessage()));

            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }
}
