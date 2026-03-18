<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Client;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use Typesense\Client;

final class TypeSenseClientFactoryTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private TypeSenseClientFactory $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sut = new TypeSenseClientFactory($this->config, $this->logger);
    }

    public function test_create_returns_typesense_client(): void
    {
        $this->config->method('getApiKey')->willReturn('test_api_key');
        $this->config->method('getHost')->willReturn('localhost');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');

        $client = $this->sut->create();

        self::assertInstanceOf(Client::class, $client);
    }

    public function test_create_with_store_id_passes_to_config(): void
    {
        $this->config->expects(self::once())->method('getApiKey')->with(42)->willReturn('test_key');
        $this->config->expects(self::once())->method('getHost')->with(42)->willReturn('remote.host');
        $this->config->expects(self::once())->method('getPort')->with(42)->willReturn(443);
        $this->config->expects(self::once())->method('getProtocol')->with(42)->willReturn('https');

        $client = $this->sut->create(42);

        self::assertInstanceOf(Client::class, $client);
    }
}
