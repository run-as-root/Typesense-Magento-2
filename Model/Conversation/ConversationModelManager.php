<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Conversation;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class ConversationModelManager
{
    private const CONVERSATION_MODEL_ID = 'rar-product-assistant';

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
            'model_name' => $this->config->getOpenAiModel($storeId),
            'api_key' => $this->config->getOpenAiApiKey($storeId),
            'history_collection' => $this->config->getIndexPrefix($storeId) . '_conversation_history',
            'system_prompt' => $this->config->getConversationalSystemPrompt($storeId),
            'max_bytes' => 16384,
            'ttl' => $this->config->getConversationTtl($storeId),
        ];
    }

    public function sync(?int $storeId = null): void
    {
        if (!$this->config->isConversationalSearchEnabled($storeId)) {
            $this->delete($storeId);
            return;
        }

        try {
            $client = $this->clientFactory->create($storeId);
            $modelConfig = $this->getModelConfig($storeId);

            try {
                $client->conversations->getModels()[self::CONVERSATION_MODEL_ID]->update($modelConfig);
            } catch (\Exception $e) {
                $client->conversations->getModels()->create($modelConfig);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to sync conversation model: ' . $e->getMessage());
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
