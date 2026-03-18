<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\IndexerOrchestratorInterface;
use RunAsRoot\TypeSense\Cron\ReindexCron;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

final class ReindexCronTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private IndexerOrchestratorInterface&MockObject $orchestrator;
    private LoggerInterface&MockObject $logger;
    private ReindexCron $cron;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->orchestrator = $this->createMock(IndexerOrchestratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cron = new ReindexCron(
            $this->config,
            $this->orchestrator,
            $this->logger,
        );
    }

    public function test_execute_calls_reindex_all_when_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isCronEnabled')->willReturn(true);

        $this->orchestrator->expects(self::once())->method('reindexAll');

        $this->cron->execute();
    }

    public function test_execute_does_nothing_when_module_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isCronEnabled')->willReturn(true);

        $this->orchestrator->expects(self::never())->method('reindexAll');
        $this->orchestrator->expects(self::never())->method('reindex');

        $this->cron->execute();
    }

    public function test_execute_does_nothing_when_cron_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isCronEnabled')->willReturn(false);

        $this->orchestrator->expects(self::never())->method('reindexAll');
        $this->orchestrator->expects(self::never())->method('reindex');

        $this->cron->execute();
    }

    public function test_execute_logs_error_on_exception(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isCronEnabled')->willReturn(true);

        $this->orchestrator->method('reindexAll')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('Connection refused'));

        $this->cron->execute();
    }
}
