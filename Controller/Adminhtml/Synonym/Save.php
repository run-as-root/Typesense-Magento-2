<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Synonym;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::synonyms';

    public function __construct(
        Context $context,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $collection = (string) $this->getRequest()->getParam('collection', '');
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/*/index', ['collection' => $collection]);

        if ($collection === '') {
            $this->messageManager->addErrorMessage(__('Collection name is required.'));

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $type = (string) $this->getRequest()->getParam('type', 'multi');
        $synonymsRaw = (string) $this->getRequest()->getParam('synonyms', '');
        $root = trim((string) $this->getRequest()->getParam('root', ''));

        $synonymWords = array_values(
            array_filter(
                array_map('trim', explode(',', $synonymsRaw)),
                static fn (string $word): bool => $word !== ''
            )
        );

        if (empty($synonymWords)) {
            $this->messageManager->addErrorMessage(__('At least one synonym word is required.'));

            return $redirect;
        }

        $payload = ['synonyms' => $synonymWords];

        if ($type === 'one-way') {
            if ($root === '') {
                $this->messageManager->addErrorMessage(__('Root word is required for one-way synonyms.'));

                return $redirect;
            }
            $payload['root'] = $root;
        }

        $synonymId = 'syn_' . time();

        try {
            $client = $this->clientFactory->create();
            $client->collections[$collection]->synonyms->upsert($synonymId, $payload);
            $this->messageManager->addSuccessMessage(__('Synonym "%1" has been saved.', $synonymId));
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed to save synonym for collection "%s": %s', $collection, $e->getMessage())
            );
            $this->messageManager->addErrorMessage(__('Could not save synonym: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
