<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\LandingPage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;
use RunAsRoot\TypeSense\Model\Curation\LandingPageMerchandisingSync;
use RunAsRoot\TypeSense\Model\Merchandising\LandingPageFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::landing_pages';

    public function __construct(
        Context $context,
        private readonly LandingPageRepositoryInterface $repository,
        private readonly LandingPageFactory $landingPageFactory,
        private readonly LandingPageMerchandisingSync $sync,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create();

        try {
            if ($id) {
                $entity = $this->repository->getById($id);
            } else {
                $entity = $this->landingPageFactory->create();
            }

            $urlKey          = (string) $this->getRequest()->getParam('url_key', '');
            $title           = (string) $this->getRequest()->getParam('title', '');
            $storeId         = (int) $this->getRequest()->getParam('store_id', 0);
            $isActive        = (bool) $this->getRequest()->getParam('is_active', false);
            $query           = (string) $this->getRequest()->getParam('query', '*');
            $filterBy        = $this->getRequest()->getParam('filter_by');
            $sortBy          = $this->getRequest()->getParam('sort_by');
            $metaDescription = $this->getRequest()->getParam('meta_description');
            $cmsContent      = $this->getRequest()->getParam('cms_content');
            $includesRaw     = (string) $this->getRequest()->getParam('includes', '[]');
            $excludesRaw     = (string) $this->getRequest()->getParam('excludes', '[]');
            $bannerRaw       = (string) $this->getRequest()->getParam('banner_config', '{}');

            if ($urlKey === '') {
                $this->messageManager->addErrorMessage(__('URL Key is required.'));

                return $id
                    ? $redirect->setPath('*/*/edit', ['id' => $id])
                    : $redirect->setPath('*/*/edit');
            }

            if ($title === '') {
                $this->messageManager->addErrorMessage(__('Title is required.'));

                return $id
                    ? $redirect->setPath('*/*/edit', ['id' => $id])
                    : $redirect->setPath('*/*/edit');
            }

            // Validate and normalise JSON fields
            $includes = $this->decodeJsonArray($includesRaw);
            $excludes = $this->decodeJsonArray($excludesRaw);
            $banner   = $this->decodeJsonArray($bannerRaw);

            $entity->setUrlKey($urlKey);
            $entity->setTitle($title);
            $entity->setStoreId($storeId);
            $entity->setIsActive($isActive);
            $entity->setQuery($query !== '' ? $query : '*');
            $entity->setFilterBy($filterBy !== null && $filterBy !== '' ? (string) $filterBy : null);
            $entity->setSortBy($sortBy !== null && $sortBy !== '' ? (string) $sortBy : null);
            $entity->setMetaDescription($metaDescription !== null && $metaDescription !== '' ? (string) $metaDescription : null);
            $entity->setCmsContent($cmsContent !== null && $cmsContent !== '' ? (string) $cmsContent : null);
            $entity->setIncludes($includes);
            $entity->setExcludes($excludes);
            $entity->setBannerConfig($banner);

            $saved = $this->repository->save($entity);
            $savedId = (int) $saved->getId();

            // Push override to Typesense
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $this->sync->sync($savedId, $storeId, $storeCode);

            $this->messageManager->addSuccessMessage(__('The landing page has been saved.'));

            return $redirect->setPath('*/*/edit', ['id' => $savedId]);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This landing page no longer exists.'));

            return $redirect->setPath('*/*/index');
        } catch (\Throwable $e) {
            $this->logger->error('LandingPage Save error: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('An error occurred while saving: %1', $e->getMessage()));

            return $id
                ? $redirect->setPath('*/*/edit', ['id' => $id])
                : $redirect->setPath('*/*/edit');
        }
    }

    /**
     * Decode a JSON string into an array. Returns empty array on failure.
     *
     * @param string $json
     * @return array<mixed>
     */
    private function decodeJsonArray(string $json): array
    {
        if ($json === '' || $json === 'null') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
