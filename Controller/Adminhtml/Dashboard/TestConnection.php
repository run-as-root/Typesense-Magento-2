<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class TestConnection extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::dashboard';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $resultJson->setData([
                'success' => false,
                'message' => 'TypeSense module is disabled. Enable it under General Settings to test the connection.',
            ]);
        }

        if ($this->config->getApiKey() === '') {
            return $resultJson->setData([
                'success' => false,
                'message' => 'Admin API Key is not configured. Please enter your Typesense API key under General Settings.',
            ]);
        }

        try {
            $client = $this->clientFactory->create();
            $health = $client->health->retrieve();

            if (($health['ok'] ?? false) === true) {
                return $resultJson->setData([
                    'success' => true,
                    'message' => 'Connected to Typesense — server is healthy.',
                ]);
            }

            return $resultJson->setData([
                'success' => false,
                'message' => 'Typesense responded but reported an unhealthy status.',
            ]);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();

            $this->logger->error(
                'TypeSense TestConnection error: ' . $errorMessage,
                ['exception' => $e]
            );

            $hint = $this->resolveErrorHint($errorMessage);

            return $resultJson->setData([
                'success' => false,
                'message' => $hint,
            ]);
        }
    }

    private function resolveErrorHint(string $errorMessage): string
    {
        if (str_contains($errorMessage, 'Connection refused')) {
            return 'Connection refused. Check that the host and port are correct and that the Typesense server is running.';
        }

        if (str_contains($errorMessage, 'timed out') || str_contains($errorMessage, 'Timeout')) {
            return 'Connection timed out. The Typesense server is unreachable. Verify the host and port, and ensure the server is running and accessible from PHP.';
        }

        if (str_contains($errorMessage, 'Could not resolve host')) {
            return 'Could not resolve the hostname. Check for DNS issues. In Docker, use the container name (e.g., \'typesense\') as the host instead of \'localhost\'.';
        }

        return $errorMessage;
    }
}
