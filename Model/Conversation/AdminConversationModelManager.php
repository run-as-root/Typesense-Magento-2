<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Conversation;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class AdminConversationModelManager
{
    private const CONVERSATION_MODEL_ID = 'rar-admin-assistant';

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getModelId(): string
    {
        return self::CONVERSATION_MODEL_ID;
    }

    public function getModelConfig(?int $storeId = null): array
    {
        return [
            'id' => self::CONVERSATION_MODEL_ID,
            'model_name' => $this->config->getAdminAssistantOpenAiModel($storeId),
            'api_key' => $this->config->getOpenAiApiKey($storeId),
            'history_collection' => $this->config->getIndexPrefix($storeId) . '_admin_conversation_history',
            'system_prompt' => $this->config->getAdminAssistantSystemPrompt($storeId),
            'max_bytes' => 65536,
            'ttl' => $this->config->getAdminAssistantConversationTtl($storeId),
        ];
    }

    public function sync(?int $storeId = null): void
    {
        if (!$this->config->isAdminAssistantEnabled($storeId)) {
            $this->delete($storeId);
            return;
        }

        try {
            $client = $this->clientFactory->create($storeId);
            $modelConfig = $this->getModelConfig($storeId);

            $this->ensureHistoryCollectionExists($client, $modelConfig['history_collection']);

            try {
                $client->conversations->getModels()[self::CONVERSATION_MODEL_ID]->update($modelConfig);
            } catch (\Exception) {
                $client->conversations->getModels()->create($modelConfig);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to sync admin conversation model: ' . $e->getMessage());
        }
    }

    private function ensureHistoryCollectionExists(\Typesense\Client $client, string $collectionName): void
    {
        try {
            $client->collections[$collectionName]->retrieve();
        } catch (\Exception) {
            $client->collections->create([
                'name' => $collectionName,
                'fields' => [
                    ['name' => 'conversation_id', 'type' => 'string'],
                    ['name' => 'model_id', 'type' => 'string'],
                    ['name' => 'timestamp', 'type' => 'int32'],
                    ['name' => 'role', 'type' => 'string', 'index' => false],
                    ['name' => 'message', 'type' => 'string', 'index' => false],
                ],
            ]);
        }
    }

    public function delete(?int $storeId = null): void
    {
        try {
            $client = $this->clientFactory->create($storeId);
            $client->conversations->getModels()[self::CONVERSATION_MODEL_ID]->delete();
        } catch (\Exception $e) {
            // Model may not exist, ignore
        }
    }
}
