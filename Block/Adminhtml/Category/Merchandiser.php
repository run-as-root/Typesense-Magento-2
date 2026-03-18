<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Category;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;

class Merchandiser extends Template implements TabInterface
{
    public function __construct(
        Context $context,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getTabLabel(): string
    {
        return (string) __('TypeSense Merchandising');
    }

    public function getTabTitle(): string
    {
        return (string) __('TypeSense Merchandising');
    }

    public function canShowTab(): bool
    {
        return true;
    }

    public function isHidden(): bool
    {
        return false;
    }

    public function getCategoryId(): ?int
    {
        $id = $this->getRequest()->getParam('id');

        return $id !== null ? (int) $id : null;
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
