<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Adminhtml;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Adminhtml\DashboardViewModel;
use Typesense\Aliases;
use Typesense\Client;
use Typesense\Collections;
use Typesense\Health;

final class DashboardViewModelTest extends TestCase
{
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private TypeSenseConfigInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private DashboardViewModel $sut;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->config        = $this->createMock(TypeSenseConfigInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->sut = new DashboardViewModel(
            $this->clientFactory,
            $this->config,
            $this->logger,
        );
    }

    public function test_is_connected_returns_true_when_health_check_succeeds(): void
    {
        $health = $this->createMock(Health::class);
        $health->method('retrieve')->willReturn(['ok' => true]);

        $client = $this->createMock(Client::class);
        $client->health = $health;

        $this->clientFactory->method('create')->willReturn($client);

        self::assertTrue($this->sut->isConnected());
    }

    public function test_is_connected_returns_false_when_health_check_throws_exception(): void
    {
        $health = $this->createMock(Health::class);
        $health->method('retrieve')->willThrowException(new \Exception('Connection refused'));

        $client = $this->createMock(Client::class);
        $client->health = $health;

        $this->clientFactory->method('create')->willReturn($client);
        $this->logger->expects(self::once())->method('error');

        self::assertFalse($this->sut->isConnected());
    }

    public function test_get_connection_info_returns_host_port_and_protocol_from_config(): void
    {
        $this->config->method('getHost')->willReturn('typesense.example.com');
        $this->config->method('getPort')->willReturn(443);
        $this->config->method('getProtocol')->willReturn('https');

        $connectionInfo = $this->sut->getConnectionInfo();

        self::assertSame('typesense.example.com', $connectionInfo['host']);
        self::assertSame(443, $connectionInfo['port']);
        self::assertSame('https', $connectionInfo['protocol']);
    }

    public function test_get_collections_returns_empty_array_on_exception(): void
    {
        $collections = $this->createMock(Collections::class);
        $collections->method('retrieve')->willThrowException(new \Exception('API error'));

        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $this->clientFactory->method('create')->willReturn($client);
        $this->logger->expects(self::once())->method('error');

        self::assertSame([], $this->sut->getCollections());
    }
}
