<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Frontend\ClientConfigViewModel;

final class ClientConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private ClientConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getSearchProtocol')->willReturn('https');
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchOnlyApiKey')->willReturn('search-only-key');
        $this->config->method('getApiKey')->willReturn('admin-key');

        $this->sut = new ClientConfigViewModel($this->config);
    }

    public function test_is_enabled_follows_module_switch(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_node_url_uses_search_node_with_port(): void
    {
        self::assertSame('https://search.example.com:8108', $this->sut->getNodeUrl());
    }

    public function test_client_config_uses_search_only_key_and_never_the_admin_key(): void
    {
        $json = $this->sut->getJsonClientConfig();

        self::assertSame(
            ['host' => 'search.example.com', 'port' => 8108, 'protocol' => 'https', 'apiKey' => 'search-only-key'],
            json_decode($json, true),
        );
        self::assertStringNotContainsString('admin-key', $json);
    }

    public function test_client_config_json_cannot_close_the_inline_script(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getSearchHost')->willReturn('</script><script>alert(1)</script>');
        $config->method('getSearchOnlyApiKey')->willReturn("key'&\"");

        $json = (new ClientConfigViewModel($config))->getJsonClientConfig();

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertStringNotContainsString('&', $json);
    }
}
