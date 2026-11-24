<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Resolver;

use Magento\Catalog\Model\Product\Link;
use Magento\Framework\App\ResourceConnection;

/**
 * Ids of a product's Magento cross-sell products, in link position order. Links are global, so the ids are the
 * same in every store; disabled products and products of another website simply find no document.
 */
class CrossSellResolver
{
    /** @var array<int, list<int>> */
    private array $idsByProduct = [];

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Loads the cross-sells of a whole indexing batch with one query. Drops what the previous batch loaded.
     *
     * @param int[] $productIds
     */
    public function preload(array $productIds): void
    {
        $this->idsByProduct = [];
        $this->load($productIds);
    }

    /** @return list<int> */
    public function getCrossSellIds(int $productId): array
    {
        if (!array_key_exists($productId, $this->idsByProduct)) {
            $this->load([$productId]);
        }

        return $this->idsByProduct[$productId];
    }

    /** @param int[] $productIds */
    private function load(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['link' => $this->resource->getTableName('catalog_product_link')],
                ['product_id', 'linked_product_id'],
            )
            ->joinLeft(
                ['attribute' => $this->resource->getTableName('catalog_product_link_attribute')],
                'attribute.link_type_id = link.link_type_id AND attribute.product_link_attribute_code = \'position\'',
                [],
            )
            ->joinLeft(
                ['position' => $this->resource->getTableName('catalog_product_link_attribute_int')],
                'position.link_id = link.link_id'
                . ' AND position.product_link_attribute_id = attribute.product_link_attribute_id',
                ['position' => 'value'],
            )
            ->where('link.link_type_id = ?', Link::LINK_TYPE_CROSSSELL)
            ->where('link.product_id IN (?)', $productIds);
        $rows = $connection->fetchAll($select);

        $linked = [];
        foreach ($rows as $row) {
            $linked[(int) $row['product_id']][] = [(int) $row['position'], (int) $row['linked_product_id']];
        }

        foreach ($productIds as $productId) {
            $links = $linked[$productId] ?? [];
            sort($links);
            $this->idsByProduct[$productId] = array_column($links, 1);
        }
    }
}
