<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class QueryCustomersTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'query_customers';
    }

    public function getDescription(): string
    {
        return 'Run SQL aggregations on customer data. Supports counting by country, counting by customer group, top customers by lifetime value, and top customers by order count.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => [
                        'count_by_country',
                        'count_by_group',
                        'top_by_lifetime_value',
                        'top_by_order_count',
                    ],
                    'description' => 'The type of aggregation to run',
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

        $connection = $this->resource->getConnection();
        $customerEntityTable = $this->resource->getTableName('customer_entity');
        $customerAddressTable = $this->resource->getTableName('customer_address_entity');
        $salesOrderTable = $this->resource->getTableName('sales_order');

        switch ($aggregation) {
            case 'count_by_country':
                $sql = "SELECT cae.country_id, COUNT(ce.entity_id) as customer_count
                        FROM {$customerEntityTable} ce
                        JOIN {$customerAddressTable} cae
                            ON cae.parent_id = ce.entity_id
                        WHERE cae.entity_id = ce.default_billing
                        GROUP BY cae.country_id
                        ORDER BY customer_count DESC
                        LIMIT {$limit}";
                break;

            case 'count_by_group':
                $sql = "SELECT ce.group_id, COUNT(ce.entity_id) as customer_count
                        FROM {$customerEntityTable} ce
                        GROUP BY ce.group_id
                        ORDER BY customer_count DESC";
                break;

            case 'top_by_lifetime_value':
                $sql = "SELECT ce.email, ce.firstname, ce.lastname,
                               SUM(so.grand_total) as lifetime_value, COUNT(so.entity_id) as order_count
                        FROM {$customerEntityTable} ce
                        JOIN {$salesOrderTable} so ON so.customer_id = ce.entity_id
                        GROUP BY ce.entity_id
                        ORDER BY lifetime_value DESC
                        LIMIT {$limit}";
                break;

            case 'top_by_order_count':
                $sql = "SELECT ce.email, ce.firstname, ce.lastname,
                               COUNT(so.entity_id) as order_count, SUM(so.grand_total) as lifetime_value
                        FROM {$customerEntityTable} ce
                        JOIN {$salesOrderTable} so ON so.customer_id = ce.entity_id
                        GROUP BY ce.entity_id
                        ORDER BY order_count DESC
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
