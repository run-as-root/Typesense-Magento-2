<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\BoostRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::boost_rules';

    // The rules are rendered into the storefront search config, which the full page cache keeps.
    private const FULL_PAGE_CACHE_TYPE = 'full_page';

    public function __construct(
        Context $context,
        private readonly BoostRuleRepositoryInterface $repository,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Invalid boosting rule ID.'));

            return $redirect;
        }

        try {
            $this->repository->delete($this->repository->getById($id));
            $this->cacheTypeList->cleanType(self::FULL_PAGE_CACHE_TYPE);
            $this->messageManager->addSuccessMessage(__('The boosting rule has been deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This boosting rule no longer exists.'));
        } catch (\Throwable $e) {
            $this->logger->error('BoostRule Delete error: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('An error occurred while deleting: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
