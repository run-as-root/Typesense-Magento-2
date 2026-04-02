<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class QueryProductsTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'query_products';
    }

    public function getDescription(): string
    {
        return 'Run SQL aggregations on product data. Supports top products by sales count, low stock products, price range statistics, and product count per category.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => [
                        'top_by_sales_count',
                        'low_stock',
                        'price_range',
                        'count_by_category',
                    ],
                    'description' => 'The type of aggregation to run',
                ],
                'threshold' => [
                    'type' => 'integer',
                    'description' => 'Stock threshold for low_stock aggregation (default 10)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (default 10)',
                ],
            ],
            'required' => ['aggregation'],
        ];
    }

    public function execute(array $arguments): string
    {
        $aggregation = $arguments['aggregation'] ?? '';
        $limit = min((int) ($arguments['limit'] ?? 10), 100);
        $threshold = (int) ($arguments['threshold'] ?? 10);

        $connection = $this->resource->getConnection();
        $productEntityTable = $this->resource->getTableName('catalog_product_entity');
        $orderItemTable = $this->resource->getTableName('sales_order_item');
        $stockItemTable = $this->resource->getTableName('cataloginventory_stock_item');
        $productDecimalTable = $this->resource->getTableName('catalog_product_entity_decimal');
        $categoryProductTable = $this->resource->getTableName('catalog_category_product');

        switch ($aggregation) {
            case 'top_by_sales_count':
                $sql = "SELECT oi.sku, oi.name, COUNT(oi.item_id) as sales_count,
                               SUM(oi.qty_ordered) as total_qty_sold
                        FROM {$orderItemTable} oi
                        WHERE oi.parent_item_id IS NULL
                        GROUP BY oi.sku
                        ORDER BY sales_count DESC
                        LIMIT {$limit}";
                break;

            case 'low_stock':
                $sql = "SELECT pe.sku, pe.entity_id, si.qty, si.is_in_stock
                        FROM {$productEntityTable} pe
                        JOIN {$stockItemTable} si ON si.product_id = pe.entity_id
                        WHERE si.qty < :threshold AND si.manage_stock = 1
                        ORDER BY si.qty ASC
                        LIMIT {$limit}";
                $rows = $connection->fetchAll($sql, [':threshold' => $threshold]);

                return json_encode([
                    'aggregation' => $aggregation,
                    'threshold' => $threshold,
                    'rows' => $rows,
                    'count' => count($rows),
                ]);

            case 'price_range':
                $sql = "SELECT MIN(pde.value) as min_price, MAX(pde.value) as max_price,
                               AVG(pde.value) as avg_price, COUNT(DISTINCT pde.entity_id) as product_count
                        FROM {$productDecimalTable} pde
                        JOIN eav_attribute ea ON ea.attribute_id = pde.attribute_id
                        WHERE ea.attribute_code = 'price' AND pde.store_id = 0";
                break;

            case 'count_by_category':
                $sql = "SELECT cp.category_id, COUNT(cp.product_id) as product_count
                        FROM {$categoryProductTable} cp
                        GROUP BY cp.category_id
                        ORDER BY product_count DESC
                        LIMIT {$limit}";
                break;

            default:
                return json_encode(['error' => 'Unknown aggregation: ' . $aggregation]);
        }

        $rows = $connection->fetchAll($sql);

        return json_encode([
            'aggregation' => $aggregation,
            'rows' => $rows,
            'count' => count($rows),
        ]);
    }
}
