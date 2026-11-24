<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Listing;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The category facet of the listing: the children of a category (category pages) or of the store root (search page),
 * filtered by `category_ids` and read from the `cat` URL parameter, as Magento's layered navigation does. The
 * children are listed like Magento's category filter lists them: active in the store, in position order, and only
 * below an active category. Their counts come from the listing's own Typesense result.
 */
class CategoryFacetResolver
{
    public const string FIELD = 'category_ids';
    public const string URL_PARAM = 'cat';

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CollectionFactory $categoryCollectionFactory,
    ) {
    }

    /**
     * @return array{
     *     field: string, param: string, label: string, type: string, searchable: false, boolean: false, numeric: string,
     *     values: non-empty-list<array{value: string, label: string}>, maxValues: int
     * }|null
     */
    public function resolve(CategoryInterface $parent, int $storeId): ?array
    {
        if (!$parent->getIsActive()) {
            return null;
        }

        $values = $this->getChildValues((int) $parent->getId(), $storeId);
        if ($values === []) {
            return null;
        }

        return [
            'field' => self::FIELD,
            'param' => self::URL_PARAM,
            'label' => (string) __('Category'),
            'type' => 'category',
            'searchable' => false,
            'boolean' => false,
            'numeric' => 'int32',
            // Ordered pairs, not an id => name object: JavaScript iterates integer keys in ascending order.
            'values' => $values,
            // Typesense returns only the max_facet_values most frequent ids, and products also carry their
            // ancestors and categories of other trees, which can outrank the children. No product carries more
            // ids than there are categories.
            'maxValues' => $this->categoryCollectionFactory->create()->getSize(),
        ];
    }

    // The search page lists the store root's top-level categories, as Magento's search layer does.
    public function resolveForStoreRoot(int $rootCategoryId, int $storeId): ?array
    {
        try {
            $root = $this->categoryRepository->get($rootCategoryId, $storeId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $this->resolve($root, $storeId);
    }

    /** @return list<array{value: string, label: string}> */
    private function getChildValues(int $parentId, int $storeId): array
    {
        $children = $this->categoryCollectionFactory->create();
        $children->setStoreId($storeId)
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('is_active', ['eq' => 1])
            ->addFieldToFilter('parent_id', ['eq' => $parentId])
            ->setOrder('position', 'ASC');

        $values = [];
        foreach ($children as $child) {
            $values[] = ['value' => (string) $child->getId(), 'label' => (string) $child->getName()];
        }

        return $values;
    }
}
