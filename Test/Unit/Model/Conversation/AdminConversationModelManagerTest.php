<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Conversation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Conversation\AdminConversationModelManager;

final class AdminConversationModelManagerTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private LoggerInterface&MockObject $logger;
    private AdminConversationModelManager $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sut = new AdminConversationModelManager($this->config, $this->clientFactory, $this->logger);
    }

    public function test_get_model_id_returns_rar_admin_assistant(): void
    {
        self::assertSame('rar-admin-assistant', $this->sut->getModelId());
    }

    public function test_get_model_config_uses_admin_specific_settings(): void
    {
        $this->config->method('getOpenAiApiKey')->willReturn('sk-test-key');
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('openai/gpt-4o-mini');
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('You are an admin assistant.');
        $this->config->method('getAdminAssistantConversationTtl')->willReturn(3600);
        $this->config->method('getIndexPrefix')->willReturn('rar');

        $modelConfig = $this->sut->getModelConfig();

        self::assertSame('rar-admin-assistant', $modelConfig['id']);
        self::assertSame('openai/gpt-4o-mini', $modelConfig['model_name']);
        self::assertSame('sk-test-key', $modelConfig['api_key']);
        self::assertSame('You are an admin assistant.', $modelConfig['system_prompt']);
        self::assertSame(16384, $modelConfig['max_bytes']);
        self::assertSame(3600, $modelConfig['ttl']);
    }

    public function test_get_model_config_uses_correct_history_collection_name(): void
    {
        $this->config->method('getOpenAiApiKey')->willReturn('sk-test-key');
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('openai/gpt-4o-mini');
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('You are an admin assistant.');
        $this->config->method('getAdminAssistantConversationTtl')->willReturn(3600);
        $this->config->method('getIndexPrefix')->willReturn('rar');

        $modelConfig = $this->sut->getModelConfig();

        self::assertSame('rar_admin_conversation_history', $modelConfig['history_collection']);
    }

    public function test_sync_calls_delete_when_admin_assistant_disabled(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);
        // delete() will attempt to create a client; swallows all exceptions
        $this->clientFactory->method('create')->willThrowException(new \RuntimeException('connection refused'));
        $this->logger->expects(self::never())->method('error');

        $this->sut->sync();
    }
}
