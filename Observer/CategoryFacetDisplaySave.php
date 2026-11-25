<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetDisplay;
use RunAsRoot\TypeSense\Plugin\Catalog\CategoryFacetDisplayDataPlugin;

/**
 * Saves the "TypeSense Filters" rows of the admin category form. The form posts a marker field, because
 * dynamic rows post nothing once every row was removed.
 */
class CategoryFacetDisplaySave implements ObserverInterface
{
    public const string SUBMITTED_FIELD = 'typesense_facet_display_submitted';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CategoryFacetDisplay $facetDisplay,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category');
        if (!$category instanceof Category || !$this->request->getParam(self::SUBMITTED_FIELD)) {
            return;
        }

        // Other categories saved in the same request (e.g. by a plugin) are not the one in the form.
        $postedId = $this->request->getParam('entity_id');
        $categoryId = (int) $category->getId();
        if ($categoryId <= 0 || ($postedId !== null && $postedId !== '' && (int) $postedId !== $categoryId)) {
            return;
        }

        $this->facetDisplay->replace($categoryId, $this->getPostedModes());
    }

    /**
     * @return array<string, int>
     */
    private function getPostedModes(): array
    {
        $posted = $this->request->getParam(CategoryFacetDisplayDataPlugin::FIELD);
        $rows = is_array($posted) ? $posted : [];
        $modes = [];
        foreach ($rows as $row) {
            $field = is_array($row) ? trim((string) ($row['attribute_code'] ?? '')) : '';
            $mode = is_array($row) && is_numeric($row['display_mode'] ?? null) ? (int) $row['display_mode'] : null;
            if ($field !== '' && in_array($mode, CategoryFacetDisplay::MODES, true)) {
                $modes[$field] = $mode;
            }
        }

        return $modes;
    }
}
