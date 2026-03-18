<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Console\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Console\Command\HealthCheckCommand;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Typesense\Client;
use Typesense\Health;

final class HealthCheckCommandTest extends TestCase
{
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private TypeSenseConfigInterface&MockObject $config;
    private HealthCheckCommand $command;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->command = new HealthCheckCommand($this->clientFactory, $this->config);
    }

    public function test_successful_health_check_outputs_ok_status(): void
    {
        $health = $this->createMock(Health::class);
        $health->method('retrieve')->willReturn(['ok' => true]);

        $client = $this->createMock(Client::class);
        $client->health = $health;

        $this->clientFactory->method('create')->willReturn($client);

        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('OK', $tester->getDisplay());
        self::assertStringContainsString('localhost', $tester->getDisplay());
    }

    public function test_failed_health_check_outputs_error_status(): void
    {
        $health = $this->createMock(Health::class);
        $health->method('retrieve')->willThrowException(new \Exception('Connection refused'));

        $client = $this->createMock(Client::class);
        $client->health = $health;

        $this->clientFactory->method('create')->willReturn($client);

        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    public function test_unhealthy_status_when_ok_is_false(): void
    {
        $health = $this->createMock(Health::class);
        $health->method('retrieve')->willReturn(['ok' => false]);

        $client = $this->createMock(Client::class);
        $client->health = $health;

        $this->clientFactory->method('create')->willReturn($client);

        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Unhealthy', $tester->getDisplay());
    }

    public function test_health_check_displays_connection_details(): void
    {
        $health = $this->createMock(Health::class);
        $health->method('retrieve')->willReturn(['ok' => true]);

        $client = $this->createMock(Client::class);
        $client->health = $health;

        $this->clientFactory->method('create')->willReturn($client);

        $this->config->method('getHost')->willReturn('typesense.example.com');
        $this->config->method('getPort')->willReturn(443);
        $this->config->method('getProtocol')->willReturn('https');

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('typesense.example.com', $output);
        self::assertStringContainsString('443', $output);
        self::assertStringContainsString('https', $output);
    }
}
