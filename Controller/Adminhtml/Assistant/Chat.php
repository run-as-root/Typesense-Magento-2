<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Assistant;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Assistant\SearchRequestBuilder;
use RunAsRoot\TypeSense\Model\Conversation\AdminConversationModelManager;

class Chat extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::ai_assistant';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly AdminConversationModelManager $modelManager,
        private readonly SearchRequestBuilder $searchRequestBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $query = (string) $this->getRequest()->getParam('query', '');
            $conversationId = (string) $this->getRequest()->getParam('conversation_id', '');

            if (trim($query) === '') {
                return $result->setData(['success' => false, 'error' => 'Query cannot be empty.']);
            }

            $store = $this->storeManager->getDefaultStoreView();
            $storeId = (int) $store->getId();
            $storeCode = $store->getCode();

            $searchRequests = $this->searchRequestBuilder->build($storeCode, $storeId);

            $commonParams = [
                'q' => $query,
                'conversation' => true,
                'conversation_model_id' => $this->modelManager->getModelId(),
            ];

            if ($conversationId !== '') {
                $commonParams['conversation_id'] = $conversationId;
            }

            $client = $this->clientFactory->create($storeId);
            $response = $client->multiSearch->perform(
                ['searches' => $searchRequests],
                $commonParams,
            );

            $answer = $response['conversation']['answer'] ?? '';
            $newConversationId = $response['conversation']['conversation_id'] ?? $conversationId;

            return $result->setData([
                'success' => true,
                'answer' => $answer,
                'conversation_id' => $newConversationId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Admin AI Assistant error: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'error' => 'Failed to get AI response. Please try again.',
            ]);
        }
    }
}
