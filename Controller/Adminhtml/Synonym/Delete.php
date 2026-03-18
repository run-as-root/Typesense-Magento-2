<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Synonym;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class Delete extends Action implements HttpPostActionInterface
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
        $synonymId = (string) $this->getRequest()->getParam('synonym_id', '');

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/*/index', ['collection' => $collection]);

        if ($collection === '') {
            $this->messageManager->addErrorMessage(__('Collection name is required.'));

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        if ($synonymId === '') {
            $this->messageManager->addErrorMessage(__('Synonym ID is required.'));

            return $redirect;
        }

        try {
            $client = $this->clientFactory->create();
            $client->collections[$collection]->synonyms[$synonymId]->delete();
            $this->messageManager->addSuccessMessage(__('Synonym "%1" has been deleted.', $synonymId));
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    'Failed to delete synonym "%s" from collection "%s": %s',
                    $synonymId,
                    $collection,
                    $e->getMessage()
                )
            );
            $this->messageManager->addErrorMessage(__('Could not delete synonym: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
