<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Router;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;

class LandingPageRouter implements RouterInterface
{
    public function __construct(
        private readonly LandingPageRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ActionFactory $actionFactory,
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $identifier = trim($request->getPathInfo(), '/');

        if (empty($identifier)) {
            return null;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('url_key', $identifier)
            ->addFilter('store_id', $storeId)
            ->addFilter('is_active', 1)
            ->create();

        $results = $this->repository->getList($searchCriteria);
        $items = $results->getItems();

        if (empty($items)) {
            return null;
        }

        $landingPage = reset($items);

        $request->setModuleName('typesense')
            ->setControllerName('landingpage')
            ->setActionName('view')
            ->setParam('id', $landingPage->getId());

        return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
    }
}
