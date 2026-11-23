<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class LayoutLoadBefore implements ObserverInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly Registry $registry,
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isReplaceCategoryPage()) {
            return;
        }

        $fullActionName = $observer->getData('full_action_name');
        if ($fullActionName !== 'catalog_category_view') {
            return;
        }

        // A static-block landing page has no product listing to replace.
        $category = $this->registry->registry('current_category');
        if ($category instanceof Category && $category->getDisplayMode() === Category::DM_PAGE) {
            return;
        }

        $layout = $observer->getData('layout');
        $layout->getUpdate()->addHandle('typesense_category_search');
    }
}
