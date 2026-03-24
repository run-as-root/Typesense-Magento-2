<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Client;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

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

    public function test_create_reads_config_with_null_store_id(): void
    {
        $this->config->expects(self::once())->method('getApiKey')->with(null)->willReturn('test_api_key');
        $this->config->expects(self::once())->method('getHost')->with(null)->willReturn('localhost');
        $this->config->expects(self::once())->method('getPort')->with(null)->willReturn(8108);
        $this->config->expects(self::once())->method('getProtocol')->with(null)->willReturn('http');

        $this->sut->create();
    }

    public function test_create_with_store_id_passes_to_config(): void
    {
        $this->config->expects(self::once())->method('getApiKey')->with(42)->willReturn('test_key');
        $this->config->expects(self::once())->method('getHost')->with(42)->willReturn('remote.host');
        $this->config->expects(self::once())->method('getPort')->with(42)->willReturn(443);
        $this->config->expects(self::once())->method('getProtocol')->with(42)->willReturn('https');

        $this->sut->create(42);
    }
}
