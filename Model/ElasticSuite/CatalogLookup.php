<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;

class CatalogLookup
{
    public const int SKU_LIMIT = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * @return list<string> SKUs containing the value anywhere (at most SKU_LIMIT), the exact SKU first
     */
    public function findSkusContaining(string $value): array
    {
        $connection = $this->resource->getConnection();
        $escaped = addcslashes($value, '%_\\');
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), ['sku'])
            ->where('sku LIKE ?', '%' . $escaped . '%')
            ->order(new \Zend_Db_Expr($connection->quoteInto('sku = ? DESC', $value)))
            ->order('sku ASC')
            ->limit(self::SKU_LIMIT);

        return array_map('strval', $connection->fetchCol($select));
    }

    public function isOptionAttribute(string $attributeCode): bool
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], ['frontend_input'])
            ->join(
                ['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                [],
            )
            ->where('t.entity_type_code = ?', Product::ENTITY)
            ->where('a.attribute_code = ?', $attributeCode);

        return in_array((string) $connection->fetchOne($select), ['select', 'multiselect', 'boolean'], true);
    }
}
