<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Assistant;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Assistant\AgentLoop;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class Chat extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::ai_assistant';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AgentLoop $agentLoop,
        private readonly LoggerInterface $logger,
        private readonly TypeSenseConfigInterface $config,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isAdminAssistantEnabled()) {
            return $result->setData(['success' => false, 'error' => 'AI Assistant is not enabled.']);
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
                    $history = $decoded;
                }
            }

            $response = $this->agentLoop->run($query, $history);

            return $result->setData([
                'success' => true,
                'answer' => $response['answer'],
                'messages' => $response['messages'],
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
