<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Synonym;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Model\Synonym\SynonymFactory;
use RunAsRoot\TypeSense\Model\Synonym\SynonymPusher;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::synonyms';

    public function __construct(
        Context $context,
        private readonly SynonymRepositoryInterface $repository,
        private readonly SynonymFactory $synonymFactory,
        private readonly SynonymPusher $pusher,
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

        // The UI lists physical collections; synonyms must be keyed by the alias so they survive version swaps.
        $aliasName = (string) preg_replace('/_v\d+$/', '', $collection);

        $type = (string) $this->getRequest()->getParam('type', SynonymInterface::TYPE_MULTI);
        $type = $type === SynonymInterface::TYPE_ONE_WAY ? SynonymInterface::TYPE_ONE_WAY : SynonymInterface::TYPE_MULTI;
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

        if ($type === SynonymInterface::TYPE_ONE_WAY && $root === '') {
            $this->messageManager->addErrorMessage(__('Root word is required for one-way synonyms.'));

            return $redirect;
        }

        $synonym = $this->synonymFactory->create();
        $synonym->setCollectionName($aliasName)
            ->setTypesenseId('syn_' . bin2hex(random_bytes(6)))
            ->setType($type)
            ->setRoot($type === SynonymInterface::TYPE_ONE_WAY ? $root : null)
            ->setSynonyms($synonymWords);

        try {
            $this->repository->save($synonym);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed to save synonym for collection "%s": %s', $aliasName, $e->getMessage())
            );
            $this->messageManager->addErrorMessage(__('Could not save synonym: %1', $e->getMessage()));

            return $redirect;
        }

        try {
            $this->pusher->push($synonym, $aliasName);
            $this->messageManager->addSuccessMessage(
                __('Synonym "%1" has been saved.', $synonym->getTypesenseId())
            );
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed to push synonym to collection "%s": %s', $aliasName, $e->getMessage())
            );
            $this->messageManager->addErrorMessage(
                __('Synonym was saved but could not be pushed to Typesense: %1', $e->getMessage())
            );
        }

        return $redirect;
    }
}
