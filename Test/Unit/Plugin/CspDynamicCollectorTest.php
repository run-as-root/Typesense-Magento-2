<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Plugin;

use Magento\Csp\Model\Policy\FetchPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Plugin\CspDynamicCollector;

final class CspDynamicCollectorTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private CspDynamicCollector $subject;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->subject = new CspDynamicCollector($this->config);
    }

    public function test_collect_adds_typesense_host_when_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getHost')->willReturn('search.example.com');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('https');
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchProtocol')->willReturn('https');

        $result = $this->subject->collect([]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(FetchPolicy::class, $result[0]);
        $this->assertSame('connect-src', $result[0]->getId());
        $this->assertContains('https://search.example.com:8108', $result[0]->getHostSources());
    }

    public function test_collect_allows_the_search_node_the_browser_connects_to(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getHost')->willReturn('typesense.internal');
        $this->config->method('getPort')->willReturn(8108);
        $this->config->method('getProtocol')->willReturn('http');
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(443);
        $this->config->method('getSearchProtocol')->willReturn('https');

        $result = $this->subject->collect([]);

        $this->assertCount(1, $result);
        $this->assertSame(
            ['http://typesense.internal:8108', 'https://search.example.com'],
            $result[0]->getHostSources(),
        );
    }

    public function test_collect_lists_a_shared_indexing_and_search_node_once(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getHost')->willReturn('search.example.com');
        $this->config->method('getPort')->willReturn(443);
        $this->config->method('getProtocol')->willReturn('https');
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(443);
        $this->config->method('getSearchProtocol')->willReturn('https');

        $this->assertSame(['https://search.example.com'], $this->subject->collect([])[0]->getHostSources());
    }

    public function test_collect_returns_default_policies_when_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $existingPolicy = new FetchPolicy('script-src', false, ['https://example.com']);
        $result = $this->subject->collect([$existingPolicy]);

        $this->assertCount(1, $result);
        $this->assertSame($existingPolicy, $result[0]);
    }

    public function test_collect_omits_port_for_standard_https(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getHost')->willReturn('search.example.com');
        $this->config->method('getPort')->willReturn(443);
        $this->config->method('getProtocol')->willReturn('https');
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(443);
        $this->config->method('getSearchProtocol')->willReturn('https');

        $result = $this->subject->collect([]);

        $this->assertCount(1, $result);
        $this->assertContains('https://search.example.com', $result[0]->getHostSources());
        foreach ($result[0]->getHostSources() as $source) {
            $this->assertStringNotContainsString(':443', $source);
        }
    }

    public function test_collect_omits_port_for_standard_http(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getHost')->willReturn('search.example.com');
        $this->config->method('getPort')->willReturn(80);
        $this->config->method('getProtocol')->willReturn('http');
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(80);
        $this->config->method('getSearchProtocol')->willReturn('http');

        $result = $this->subject->collect([]);

        $this->assertCount(1, $result);
        $this->assertContains('http://search.example.com', $result[0]->getHostSources());
        foreach ($result[0]->getHostSources() as $source) {
            $this->assertStringNotContainsString(':80', $source);
        }
    }
}
