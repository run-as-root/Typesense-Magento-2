<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Collection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::collections';

    public function __construct(
        Context $context,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/*/index');

        $name = (string) $this->getRequest()->getParam('name', '');

        if ($name === '') {
            $this->messageManager->addErrorMessage(__('Collection name is required.'));

            return $redirect;
        }

        try {
            $client = $this->clientFactory->create();
            $client->collections[$name]->delete();
            $this->messageManager->addSuccessMessage(__('Collection "%1" has been deleted.', $name));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to delete TypeSense collection "%s": %s', $name, $e->getMessage()));
            $this->messageManager->addErrorMessage(
                __('Could not delete collection "%1": %2', $name, $e->getMessage())
            );
        }

        return $redirect;
    }
}
