<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * Typesense listing layouts live in their own handles so that a disabled module or setting leaves
 * Magento's own search and category pages untouched.
 */
class LayoutLoadBefore implements ObserverInterface
{
    public const string CATEGORY_HANDLE = 'typesense_category_search';
    public const string SEARCH_HANDLE = 'typesense_search_result';

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly Registry $registry,
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $handle = match ($observer->getData('full_action_name')) {
            'catalog_category_view' => $this->isTypesenseCategoryPage() ? self::CATEGORY_HANDLE : null,
            'catalogsearch_result_index' => $this->config->isInstantSearchEnabled() ? self::SEARCH_HANDLE : null,
            default => null,
        };

        if ($handle !== null) {
            $observer->getData('layout')->getUpdate()->addHandle($handle);
        }
    }

    private function isTypesenseCategoryPage(): bool
    {
        if (!$this->config->isReplaceCategoryPage()) {
            return false;
        }

        // A static-block landing page has no product listing to replace.
        $category = $this->registry->registry('current_category');

        return !($category instanceof Category && $category->getDisplayMode() === Category::DM_PAGE);
    }
}
