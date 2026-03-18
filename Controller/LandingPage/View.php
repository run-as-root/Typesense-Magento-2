<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\LandingPage;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;

class View extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly LandingPageRepositoryInterface $repository,
        private readonly PageFactory $pageFactory,
        private readonly Registry $registry,
        private readonly ForwardFactory $forwardFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface
    {
        $id = (int) $this->getRequest()->getParam('id');

        try {
            $landingPage = $this->repository->getById($id);
        } catch (NoSuchEntityException) {
            $forward = $this->forwardFactory->create();
            $forward->forward('noroute');
            return $forward;
        }

        if (!$landingPage->isActive()) {
            $forward = $this->forwardFactory->create();
            $forward->forward('noroute');
            return $forward;
        }

        $this->registry->register('current_landing_page', $landingPage);

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set($landingPage->getTitle());

        $metaDescription = $landingPage->getMetaDescription();
        if ($metaDescription) {
            $page->getConfig()->setDescription($metaDescription);
        }

        return $page;
    }
}
