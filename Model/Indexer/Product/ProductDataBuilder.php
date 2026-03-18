<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

class ProductDataBuilder
{
    public function __construct(
        private readonly AttributeResolverInterface $attributeResolver,
        private readonly PriceCalculatorInterface $priceCalculator,
        private readonly ImageResolverInterface $imageResolver,
        private readonly StockResolverInterface $stockResolver,
        private readonly CategoryResolverInterface $categoryResolver,
        private readonly UrlResolverInterface $urlResolver,
        private readonly ProductCollectionFactory $collectionFactory,
    ) {
    }

    /**
     * Build a Typesense document array from a product.
     *
     * @return array<string, mixed>
     */
    public function build(Product $product, int $storeId): array
    {
        $productId = (int) $product->getId();
        $categoryData = $this->categoryResolver->getCategoryData($product);
        $specialPrice = $this->priceCalculator->getSpecialPrice($product);

        $document = [
            'id' => (string) $productId,
            'product_id' => $productId,
            'name' => (string) $product->getName(),
            'sku' => (string) $product->getSku(),
            'url' => $this->urlResolver->getProductUrl($product),
            'image_url' => $this->imageResolver->getImageUrl($product),
            'price' => $this->priceCalculator->getFinalPrice($product),
            'categories' => $categoryData['categories'],
            'category_ids' => $categoryData['category_ids'],
            'categories.lvl0' => $categoryData['categories.lvl0'],
            'categories.lvl1' => $categoryData['categories.lvl1'],
            'categories.lvl2' => $categoryData['categories.lvl2'],
            'in_stock' => $this->stockResolver->isInStock($product),
            'type_id' => (string) $product->getTypeId(),
            'visibility' => (int) $product->getVisibility(),
            'created_at' => $this->toTimestamp((string) $product->getCreatedAt()),
            'updated_at' => $this->toTimestamp((string) $product->getUpdatedAt()),
        ];

        if ($specialPrice !== null) {
            $document['special_price'] = $specialPrice;
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
        $collection->setVisibility([
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_BOTH,
        ]);

        if ($entityIds !== []) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
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
