<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Assistant;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Assistant\AgentLoop;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class Chat extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::ai_assistant';

    private const RATE_LIMIT_MAX_REQUESTS = 100;
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AgentLoop $agentLoop,
        private readonly LoggerInterface $logger,
        private readonly TypeSenseConfigInterface $config,
        private readonly CacheInterface $cache,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isAdminAssistantEnabled()) {
            return $result->setData(['success' => false, 'error' => 'AI Assistant is not enabled.']);
        }

        $rateLimitError = $this->checkRateLimit();
        if ($rateLimitError !== null) {
            return $result->setData(['success' => false, 'error' => $rateLimitError]);
        }

        try {
            $query = (string) $this->getRequest()->getParam('query', '');
            $historyJson = (string) $this->getRequest()->getParam('history', '');

            if (trim($query) === '') {
                return $result->setData(['success' => false, 'error' => 'Query cannot be empty.']);
            }

            $history = [];
            if ($historyJson !== '') {
                $decoded = json_decode($historyJson, true);
                if (is_array($decoded)) {
                    $history = $this->sanitizeHistory($decoded);
                }
            }

            $response = $this->agentLoop->run($query, $history);

            return $result->setData([
                'success' => true,
                'answer' => $response['answer'],
                'messages' => $this->filterResponseMessages($response['messages']),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Admin AI Assistant error: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'error' => 'Failed to get AI response. Please try again.',
            ]);
        }
    }

    private function checkRateLimit(): ?string
    {
        $adminId = (string) ($this->_auth->getUser()?->getId() ?? 'unknown');
        $cacheKey = 'ai_assistant_rate_' . $adminId;

        $count = (int) ($this->cache->load($cacheKey) ?: 0);

        if ($count >= self::RATE_LIMIT_MAX_REQUESTS) {
            $this->logger->warning('AI Assistant rate limit exceeded for admin ' . $adminId);
            return 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_MAX_REQUESTS
                . ' requests per hour. Please try again later.';
        }

        $this->cache->save(
            (string) ($count + 1),
            $cacheKey,
            [],
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        return null;
    }

    /**
     * Filter response messages to prevent leaking system prompt and tool internals.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function filterResponseMessages(array $messages): array
    {
        $exposedRoles = ['user', 'assistant'];
        $filtered = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            if (!in_array($role, $exposedRoles, true)) {
                continue;
            }

            $filtered[] = [
                'role' => $role,
                'content' => (string) ($message['content'] ?? ''),
            ];
        }

        return $filtered;
    }

    /**
     * Sanitize conversation history from client input.
     * Only allow user and assistant roles. Strip tool_calls and system messages.
     *
     * @param array<int, mixed> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitizeHistory(array $history): array
    {
        $allowedRoles = ['user', 'assistant'];
        $sanitized = [];

        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? '');
            if (!in_array($role, $allowedRoles, true)) {
                continue;
            }

            $sanitized[] = [
                'role' => $role,
                'content' => (string) ($message['content'] ?? ''),
            ];
        }

        return $sanitized;
    }
}
