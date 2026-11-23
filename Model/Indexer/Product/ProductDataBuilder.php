<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\NameDecompounder;
use RunAsRoot\TypeSense\Model\Indexer\Product\Resolver\GroupedChildrenResolver;
use RunAsRoot\TypeSense\Model\Indexer\Product\Resolver\SalableResolverInterface;

class ProductDataBuilder
{
    public function __construct(
        private readonly AttributeResolverInterface $attributeResolver,
        private readonly PriceCalculatorInterface $priceCalculator,
        private readonly ImageResolverInterface $imageResolver,
        private readonly SalableResolverInterface $salableResolver,
        private readonly CategoryResolverInterface $categoryResolver,
        private readonly UrlResolverInterface $urlResolver,
        private readonly ProductCollectionFactory $collectionFactory,
        private readonly SalesCountResolverInterface $salesCountResolver,
        private readonly ReviewResolverInterface $reviewResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeSenseConfigInterface $config,
        private readonly NameDecompounder $nameDecompounder,
        private readonly GroupedChildrenResolver $groupedChildrenResolver,
        private readonly NewProductResolverInterface $newProductResolver,
    ) {
    }

    /**
     * Build a Typesense document array from a product, or null when the product must not be indexed.
     *
     * @return array<string, mixed>|null
     */
    public function build(Product $product, int $storeId): ?array
    {
        $listingData = $this->getListingData($product, $storeId);
        if ($listingData === null) {
            return null;
        }

        $productId = (int) $product->getId();
        $categoryData = $this->categoryResolver->getCategoryData($product);

        $document = [
            'id' => (string) $productId,
            'product_id' => $productId,
            'name' => (string) $product->getName(),
            'sku' => (string) $product->getSku(),
            'url' => $this->urlResolver->getProductUrl($product),
            'image_url' => $this->imageResolver->getImageUrl($product),
            'price' => $listingData['final_price'],
            'final_price' => $listingData['final_price'],
            'regular_price' => $listingData['regular_price'],
            'categories' => $categoryData['categories'],
            'category_ids' => $categoryData['category_ids'],
            'categories.lvl0' => $categoryData['categories.lvl0'],
            'categories.lvl1' => $categoryData['categories.lvl1'],
            'categories.lvl2' => $categoryData['categories.lvl2'],
            'categories_text' => implode(', ', $categoryData['categories']),
            'in_stock' => $listingData['in_stock'],
            'is_new' => $this->newProductResolver->isNew($product, $storeId),
            'type_id' => (string) $product->getTypeId(),
            'visibility' => (int) $product->getVisibility(),
            'created_at' => $this->toTimestamp((string) $product->getCreatedAt()),
            'updated_at' => $this->toTimestamp((string) $product->getUpdatedAt()),
        ];

        if (isset($listingData['children'])) {
            $document['children'] = $listingData['children'];
        }

        $description = $product->getData('description');
        if ($description !== null && $description !== '') {
            $document['description'] = strip_tags((string) $description);
        }

        $shortDescription = $product->getData('short_description');
        if ($shortDescription !== null && $shortDescription !== '') {
            $document['short_description'] = strip_tags((string) $shortDescription);
        }

        $extra = $this->attributeResolver->getExtraAttributes($product);
        if ($extra !== []) {
            $document = array_merge($document, $extra);
        }

        if ($this->config->isGermanDecompoundingEnabled($storeId)) {
            $document[NameDecompounder::FIELD] = $this->nameDecompounder->decompound(
                $document['name'],
                $this->getSkipWordsSource($product, $storeId),
                $storeId,
            );
        }

        $document['sales_count'] = $this->salesCountResolver->getSalesCount($productId);
        $document['rating_summary'] = $this->reviewResolver->getRatingSummary($productId, $storeId);
        $document['review_count'] = $this->reviewResolver->getReviewCount($productId, $storeId);

        return $document;
    }

    /**
     * Load a product collection filtered by entity IDs, scoped to a store.
     *
     * @param int[] $entityIds
     */
    public function getProductCollection(array $entityIds, int $storeId): ProductCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addUrlRewrite();
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addWebsiteFilter((int) $this->storeManager->getStore($storeId)->getWebsiteId());
        // Catalog-only products are indexed too so category listings can show them; search filters them out.
        // Not setVisibility(): on a store collection it joins catalog_category_product_index, so a stale
        // category index decides what gets indexed and products in no category are dropped.
        $collection->addAttributeToFilter('visibility', ['in' => [
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_BOTH,
        ]]);

        if ($entityIds !== []) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
    }

    /**
     * @return array{final_price: float, regular_price: float, in_stock: bool, children?: list<array<string, mixed>>}|null
     */
    private function getListingData(Product $product, int $storeId): ?array
    {
        if ($product->getTypeId() !== Grouped::TYPE_CODE) {
            return [
                'final_price' => $this->priceCalculator->getFinalPrice($product),
                'regular_price' => $this->priceCalculator->getRegularPrice($product),
                'in_stock' => $this->salableResolver->isSalable($product, $storeId),
            ];
        }

        $children = $this->groupedChildrenResolver->getChildren($product, $storeId);
        if ($children === []) {
            return null;
        }

        $salableChildren = array_filter($children, static fn (array $child): bool => $child['in_stock']);
        // The "from" price is the cheapest buyable size; only when nothing is buyable does any size count,
        // so an out-of-stock tile still shows a real price instead of 0.
        $cheapest = $this->getCheapestChild($salableChildren !== [] ? $salableChildren : $children);

        return [
            'final_price' => $cheapest['final_price'],
            'regular_price' => $cheapest['regular_price'],
            'in_stock' => $salableChildren !== [],
            'children' => $children,
        ];
    }

    /**
     * @param non-empty-array<array{final_price: float, regular_price: float}> $children
     * @return array{final_price: float, regular_price: float}
     */
    private function getCheapestChild(array $children): array
    {
        $cheapest = reset($children);
        foreach ($children as $child) {
            if ($child['final_price'] < $cheapest['final_price']) {
                $cheapest = $child;
            }
        }

        return $cheapest;
    }

    private function getSkipWordsSource(Product $product, int $storeId): string
    {
        $code = $this->config->getDecompoundSkipAttribute($storeId);
        if ($code === '') {
            return '';
        }

        $attribute = $product->getResource()->getAttribute($code);
        if (!$attribute) {
            return '';
        }

        if (in_array($attribute->getFrontendInput(), ['select', 'multiselect'], true)) {
            $text = $product->getAttributeText($code);

            return is_array($text) ? implode(' ', $text) : (string) $text;
        }

        return (string) $product->getData($code);
    }

    private function toTimestamp(string $dateString): int
    {
        if ($dateString === '') {
            return 0;
        }

        $ts = strtotime($dateString);

        return $ts !== false ? $ts : 0;
    }
}
