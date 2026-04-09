<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class AgentLoop
{
    private const MAX_ITERATIONS = 10;

    public function __construct(
        private readonly OpenAiClientFactory $openAiClientFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run the agent loop for a user question.
     *
     * @param string $userQuery The user's question
     * @param array<int, array<string, mixed>> $conversationHistory Previous messages for context
     * @return array{answer: string, messages: array<int, array<string, mixed>>}
     */
    public function run(string $userQuery, array $conversationHistory = []): array
    {
        $messages = $conversationHistory;

        if (empty($messages)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->config->getAdminAssistantSystemPrompt(),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $userQuery];

        $tools = $this->toolRegistry->getToolDefinitions();
        $client = $this->openAiClientFactory->create();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $client->chat()->create([
                'model' => $this->resolveModel(),
                'messages' => $messages,
                'tools' => $tools,
            ]);

            $choice = $response->choices[0];
            $assistantMessage = $choice->message;

            // Build assistant message for history
            $messageData = ['role' => 'assistant', 'content' => $assistantMessage->content];
            if (!empty($assistantMessage->toolCalls)) {
                $messageData['tool_calls'] = array_map(static fn($tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->function->name,
                        'arguments' => $tc->function->arguments,
                    ],
                ], $assistantMessage->toolCalls);
            }
            $messages[] = $messageData;

            // If no tool calls, we have our final answer
            if ($choice->finishReason === 'stop' || empty($assistantMessage->toolCalls)) {
                return [
                    'answer' => $assistantMessage->content ?? '',
                    'messages' => $messages,
                ];
            }

            // Execute each tool call and append results
            foreach ($assistantMessage->toolCalls as $toolCall) {
                $toolName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true) ?? [];

                try {
                    $tool = $this->toolRegistry->getTool($toolName);
                    $result = $tool->execute($arguments);
                } catch (\Exception $e) {
                    $this->logger->error("Agent tool error ({$toolName}): " . $e->getMessage());
                    $result = json_encode(['error' => $e->getMessage()]);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => $result,
                ];
            }
        }

        // Max iterations reached — return a graceful fallback
        return [
            'answer' => 'I was unable to find a complete answer within the allowed steps. Please try rephrasing your question.',
            'messages' => $messages,
        ];
    }

    private function resolveModel(): string
    {
        $model = $this->config->getAdminAssistantOpenAiModel();

        // Strip 'openai/' prefix if present (Typesense format vs OpenAI format)
        if (str_starts_with($model, 'openai/')) {
            return substr($model, 7);
        }

        return $model ?: 'gpt-4o';
    }
}
