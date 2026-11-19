<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Synonym;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use Typesense\Exceptions\ObjectNotFound;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::synonyms';

    public function __construct(
        Context $context,
        private readonly SynonymRepositoryInterface $repository,
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

        $aliasName = (string) preg_replace('/_v\d+$/', '', $collection);

        try {
            // Remote first: a Typesense failure then leaves both copies intact and the delete can be retried.
            $this->deleteRemote($aliasName, $synonymId);
            $this->deleteStored($aliasName, $synonymId);
            $this->messageManager->addSuccessMessage(__('Synonym "%1" has been deleted.', $synonymId));
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    'Failed to delete synonym "%s" from collection "%s": %s',
                    $synonymId,
                    $aliasName,
                    $e->getMessage()
                )
            );
            $this->messageManager->addErrorMessage(__('Could not delete synonym: %1', $e->getMessage()));
        }

        return $redirect;
    }

    private function deleteStored(string $aliasName, string $synonymId): void
    {
        try {
            $synonym = $this->repository->getByTypesenseId($aliasName, $synonymId);
        } catch (NoSuchEntityException) {
            // Synonyms created before they were persisted exist only in Typesense.
            return;
        }

        $this->repository->delete($synonym);
    }

    private function deleteRemote(string $aliasName, string $synonymId): void
    {
        try {
            $client = $this->clientFactory->create();
            $client->collections[$aliasName]->synonyms[$synonymId]->delete();
        } catch (ObjectNotFound) {
            // Already gone from the live collection, which is the state we want.
        }
    }
}
