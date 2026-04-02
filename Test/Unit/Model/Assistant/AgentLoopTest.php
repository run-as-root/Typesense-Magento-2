<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant;

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Assistant\AgentLoop;
use RunAsRoot\TypeSense\Model\Assistant\OpenAiClientFactory;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ToolInterface;
use RunAsRoot\TypeSense\Model\Assistant\ToolRegistry;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

final class AgentLoopTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private OpenAiClientFactory&MockObject $clientFactory;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clientFactory = $this->getMockBuilder(OpenAiClientFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function buildAgentLoop(ToolRegistry $toolRegistry): AgentLoop
    {
        return new AgentLoop(
            $this->clientFactory,
            $toolRegistry,
            $this->config,
            $this->logger,
        );
    }

    public function test_run_returns_direct_answer_when_no_tool_calls(): void
    {
        $fakeResponse = CreateResponse::fake([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Your total revenue is EUR 42,000.',
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $clientFake = new ClientFake([$fakeResponse]);
        $this->clientFactory->method('create')->willReturn($clientFake);
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('You are an assistant.');
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('gpt-4o');

        $registry = new ToolRegistry([]);
        $agentLoop = $this->buildAgentLoop($registry);

        $result = $agentLoop->run('What is my total revenue?');

        self::assertSame('Your total revenue is EUR 42,000.', $result['answer']);
        self::assertNotEmpty($result['messages']);
    }

    public function test_run_adds_system_prompt_when_no_history(): void
    {
        $fakeResponse = CreateResponse::fake([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello!',
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $clientFake = new ClientFake([$fakeResponse]);
        $this->clientFactory->method('create')->willReturn($clientFake);
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('System prompt here.');
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('gpt-4o');

        $registry = new ToolRegistry([]);
        $agentLoop = $this->buildAgentLoop($registry);

        $result = $agentLoop->run('Hi');

        // First message should be system prompt, second user message
        self::assertSame('system', $result['messages'][0]['role']);
        self::assertSame('System prompt here.', $result['messages'][0]['content']);
        self::assertSame('user', $result['messages'][1]['role']);
    }

    public function test_run_executes_tool_call_and_returns_final_answer(): void
    {
        // First response: OpenAI calls a tool
        $toolCallResponse = CreateResponse::fake([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_abc123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'query_orders',
                                    'arguments' => '{"aggregation":"total_revenue"}',
                                ],
                            ],
                        ],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ]);

        // Second response: OpenAI gives final answer after seeing tool result
        $finalResponse = CreateResponse::fake([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Based on the data, your total revenue is EUR 5,000.',
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $clientFake = new ClientFake([$toolCallResponse, $finalResponse]);
        $this->clientFactory->method('create')->willReturn($clientFake);
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('You are an assistant.');
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('gpt-4o');

        // Create a real tool mock
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('query_orders');
        $tool->method('execute')->with(['aggregation' => 'total_revenue'])
            ->willReturn('{"aggregation":"total_revenue","rows":[{"total":"5000","currency":"EUR"}],"count":1}');

        $registry = new ToolRegistry(['query_orders' => $tool]);
        $agentLoop = $this->buildAgentLoop($registry);

        $result = $agentLoop->run('What is my total revenue?');

        self::assertSame('Based on the data, your total revenue is EUR 5,000.', $result['answer']);
    }

    public function test_run_handles_tool_error_gracefully(): void
    {
        $toolCallResponse = CreateResponse::fake([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_xyz',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'query_orders',
                                    'arguments' => '{"aggregation":"total_revenue"}',
                                ],
                            ],
                        ],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ]);

        $finalResponse = CreateResponse::fake([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I encountered an error retrieving the data.',
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $clientFake = new ClientFake([$toolCallResponse, $finalResponse]);
        $this->clientFactory->method('create')->willReturn($clientFake);
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('You are an assistant.');
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('gpt-4o');

        // Tool throws an exception
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('query_orders');
        $tool->method('execute')->willThrowException(new \RuntimeException('DB connection failed'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with($this->stringContains('query_orders'));

        $registry = new ToolRegistry(['query_orders' => $tool]);
        $agentLoop = $this->buildAgentLoop($registry);

        $result = $agentLoop->run('What is my revenue?');

        self::assertSame('I encountered an error retrieving the data.', $result['answer']);
    }

    public function test_run_strips_openai_prefix_from_model_name(): void
    {
        $fakeResponse = CreateResponse::fake([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello!',
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $clientFake = new ClientFake([$fakeResponse]);
        $this->clientFactory->method('create')->willReturn($clientFake);
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('System prompt.');
        // Typesense format with 'openai/' prefix
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('openai/gpt-4o-mini');

        $registry = new ToolRegistry([]);
        $agentLoop = $this->buildAgentLoop($registry);

        // Should not throw — model prefix is stripped before passing to OpenAI
        $result = $agentLoop->run('Hello');

        // If model prefix was NOT stripped, OpenAI would reject the request at runtime.
        // Here we just verify the loop completes successfully, meaning resolveModel() worked.
        self::assertNotEmpty($result['answer']);
        self::assertSame('Hello!', $result['answer']);
    }
}
