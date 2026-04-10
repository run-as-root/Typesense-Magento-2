<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class GeographicPerformanceTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function getName(): string
    {
        return 'geographic_performance';
    }

    public function getDescription(): string
    {
        return 'Analyze sales performance by geography: revenue by country, revenue by region/state, top cities, average order value by country, and product preferences by country.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['revenue_by_country', 'revenue_by_region', 'top_cities', 'aov_by_country', 'product_preferences_by_country'],
                    'description' => 'The type of geographic analysis to perform.',
                ],
                'country' => [
                    'type' => 'string',
                    'description' => 'Optional 2-letter ISO country code to filter results (e.g. US, DE, GB).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return (default 10).',
                    'default' => 10,
                ],
            ],
            'required' => ['aggregation'],
        ];
    }

    public function execute(array $arguments): string
    {
        $aggregation = $arguments['aggregation'] ?? '';
        $country = isset($arguments['country']) ? strtoupper(trim((string) $arguments['country'])) : null;
        $limit = max(1, (int) ($arguments['limit'] ?? 10));

        $validAggregations = ['revenue_by_country', 'revenue_by_region', 'top_cities', 'aov_by_country', 'product_preferences_by_country'];
        if (!in_array($aggregation, $validAggregations, true)) {
            return json_encode(['error' => 'Invalid aggregation: ' . $aggregation]);
        }

        try {
            return match ($aggregation) {
                'revenue_by_country' => $this->revenueByCountry($country, $limit),
                'revenue_by_region' => $this->revenueByRegion($country, $limit),
                'top_cities' => $this->topCities($country, $limit),
                'aov_by_country' => $this->aovByCountry($country, $limit),
                'product_preferences_by_country' => $this->productPreferencesByCountry($country, $limit),
            };
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query error: ' . $e->getMessage()]);
        }
    }

    private function revenueByCountry(?string $country, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soa = $this->resource->getTableName('sales_order_address');

        $countryFilter = $country ? 'AND soa.country_id = :country' : '';
        $params = ['limit' => $limit];
        if ($country) {
            $params['country'] = $country;
        }

        $sql = "SELECT
                    soa.country_id,
                    COUNT(DISTINCT so.entity_id) as order_count,
                    ROUND(SUM(so.grand_total), 2) as revenue,
                    ROUND(AVG(so.grand_total), 2) as avg_order_value
                FROM {$so} so
                JOIN {$soa} soa ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping'
                WHERE so.status != 'canceled'
                    {$countryFilter}
                GROUP BY soa.country_id
                ORDER BY revenue DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, $params);

        return json_encode([
            'aggregation' => 'revenue_by_country',
            'country_filter' => $country,
            'rows' => array_map(fn(array $r) => [
                'country_id' => $r['country_id'],
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
            ], $rows),
        ]);
    }

    private function revenueByRegion(?string $country, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soa = $this->resource->getTableName('sales_order_address');

        $countryFilter = $country ? 'AND soa.country_id = :country' : '';
        $params = ['limit' => $limit];
        if ($country) {
            $params['country'] = $country;
        }

        $sql = "SELECT
                    soa.country_id,
                    soa.region,
                    COUNT(DISTINCT so.entity_id) as order_count,
                    ROUND(SUM(so.grand_total), 2) as revenue
                FROM {$so} so
                JOIN {$soa} soa ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping'
                WHERE so.status != 'canceled'
                    AND soa.region IS NOT NULL
                    AND soa.region != ''
                    {$countryFilter}
                GROUP BY soa.country_id, soa.region
                ORDER BY revenue DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, $params);

        return json_encode([
            'aggregation' => 'revenue_by_region',
            'country_filter' => $country,
            'rows' => array_map(fn(array $r) => [
                'country_id' => $r['country_id'],
                'region' => $r['region'],
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
            ], $rows),
        ]);
    }

    private function topCities(?string $country, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soa = $this->resource->getTableName('sales_order_address');

        $countryFilter = $country ? 'AND soa.country_id = :country' : '';
        $params = ['limit' => $limit];
        if ($country) {
            $params['country'] = $country;
        }

        $sql = "SELECT
                    soa.country_id,
                    soa.city,
                    COUNT(DISTINCT so.entity_id) as order_count,
                    ROUND(SUM(so.grand_total), 2) as revenue
                FROM {$so} so
                JOIN {$soa} soa ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping'
                WHERE so.status != 'canceled'
                    AND soa.city IS NOT NULL
                    AND soa.city != ''
                    {$countryFilter}
                GROUP BY soa.country_id, soa.city
                ORDER BY order_count DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, $params);

        return json_encode([
            'aggregation' => 'top_cities',
            'country_filter' => $country,
            'rows' => array_map(fn(array $r) => [
                'country_id' => $r['country_id'],
                'city' => $r['city'],
                'order_count' => (int) $r['order_count'],
                'revenue' => round((float) $r['revenue'], 2),
            ], $rows),
        ]);
    }

    private function aovByCountry(?string $country, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soa = $this->resource->getTableName('sales_order_address');

        $countryFilter = $country ? 'AND soa.country_id = :country' : '';
        $params = ['limit' => $limit];
        if ($country) {
            $params['country'] = $country;
        }

        $sql = "SELECT
                    soa.country_id,
                    COUNT(DISTINCT so.entity_id) as order_count,
                    ROUND(AVG(so.grand_total), 2) as avg_order_value,
                    ROUND(MIN(so.grand_total), 2) as min_order_value,
                    ROUND(MAX(so.grand_total), 2) as max_order_value
                FROM {$so} so
                JOIN {$soa} soa ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping'
                WHERE so.status != 'canceled'
                    {$countryFilter}
                GROUP BY soa.country_id
                HAVING order_count >= 5
                ORDER BY avg_order_value DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, $params);

        return json_encode([
            'aggregation' => 'aov_by_country',
            'country_filter' => $country,
            'rows' => array_map(fn(array $r) => [
                'country_id' => $r['country_id'],
                'order_count' => (int) $r['order_count'],
                'avg_order_value' => round((float) $r['avg_order_value'], 2),
                'min_order_value' => round((float) $r['min_order_value'], 2),
                'max_order_value' => round((float) $r['max_order_value'], 2),
            ], $rows),
        ]);
    }

    private function productPreferencesByCountry(?string $country, int $limit): string
    {
        $connection = $this->resource->getConnection();
        $so = $this->resource->getTableName('sales_order');
        $soa = $this->resource->getTableName('sales_order_address');
        $soi = $this->resource->getTableName('sales_order_item');

        $countryFilter = $country ? 'AND soa.country_id = :country' : '';
        $params = ['limit' => $limit];
        if ($country) {
            $params['country'] = $country;
        }

        $sql = "SELECT
                    soa.country_id,
                    soi.sku,
                    soi.name,
                    SUM(soi.qty_ordered) as units_ordered,
                    ROUND(SUM(soi.row_total), 2) as revenue
                FROM {$so} so
                JOIN {$soa} soa ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping'
                JOIN {$soi} soi ON soi.order_id = so.entity_id AND soi.parent_item_id IS NULL
                WHERE so.status != 'canceled'
                    {$countryFilter}
                GROUP BY soa.country_id, soi.sku, soi.name
                ORDER BY soa.country_id, units_ordered DESC
                LIMIT :limit";

        $rows = $connection->fetchAll($sql, $params);

        return json_encode([
            'aggregation' => 'product_preferences_by_country',
            'country_filter' => $country,
            'rows' => array_map(fn(array $r) => [
                'country_id' => $r['country_id'],
                'sku' => $r['sku'],
                'name' => $r['name'],
                'units_ordered' => (int) $r['units_ordered'],
                'revenue' => round((float) $r['revenue'], 2),
            ], $rows),
        ]);
    }
}
