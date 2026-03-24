<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Conversation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Conversation\ConversationModelManager;

final class ConversationModelManagerTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private LoggerInterface&MockObject $logger;
    private ConversationModelManager $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sut = new ConversationModelManager($this->config, $this->clientFactory, $this->logger);
    }

    public function test_get_model_id_returns_constant(): void
    {
        self::assertSame('rar-product-assistant', $this->sut->getModelId());
    }

    public function test_get_model_config_returns_expected_structure(): void
    {
        $this->config->method('getOpenAiApiKey')->willReturn('sk-test-key');
        $this->config->method('getOpenAiModel')->willReturn('openai/gpt-4o-mini');
        $this->config->method('getConversationalSystemPrompt')->willReturn('You are helpful.');
        $this->config->method('getConversationTtl')->willReturn(86400);
        $this->config->method('getIndexPrefix')->willReturn('rar');

        $modelConfig = $this->sut->getModelConfig();

        self::assertSame('openai/gpt-4o-mini', $modelConfig['model_name']);
        self::assertSame('sk-test-key', $modelConfig['api_key']);
        self::assertSame('rar_conversation_history', $modelConfig['history_collection']);
        self::assertSame('You are helpful.', $modelConfig['system_prompt']);
        self::assertSame(16384, $modelConfig['max_bytes']);
        self::assertSame(86400, $modelConfig['ttl']);
    }
}
