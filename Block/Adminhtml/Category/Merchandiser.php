<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Category;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class Merchandiser extends Template
{
    protected $_template = 'RunAsRoot_TypeSense::category/merchandiser.phtml';

    public function __construct(
        Context $context,
        private readonly TypeSenseConfigInterface $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isCategoryMerchandiserEnabled();
    }

    public function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }

    public function getCategoryId(): ?int
    {
        $id = $this->getRequest()->getParam('id');

        return $id !== null ? (int) $id : null;
    }

    public function getLoadUrl(): string
    {
        return $this->getUrl('typesense/categorymerchandiser/load');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('typesense/categorymerchandiser/save');
    }

    public function getProductSearchUrl(): string
    {
        return $this->getUrl('typesense/categorymerchandiser/productsearch');
    }
}
