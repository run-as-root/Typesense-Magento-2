<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use RunAsRoot\TypeSense\Api\OrderSchemaProviderInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class OrderSchemaProvider implements OrderSchemaProviderInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        $fields = [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'order_id', 'type' => 'int32'],
            ['name' => 'increment_id', 'type' => 'string'],
            ['name' => 'status', 'type' => 'string', 'facet' => true],
            ['name' => 'state', 'type' => 'string', 'facet' => true],
            ['name' => 'customer_email', 'type' => 'string'],
            ['name' => 'customer_name', 'type' => 'string'],
            ['name' => 'customer_group', 'type' => 'string', 'facet' => true],
            ['name' => 'grand_total', 'type' => 'float'],
            ['name' => 'subtotal', 'type' => 'float'],
            ['name' => 'tax_amount', 'type' => 'float'],
            ['name' => 'shipping_amount', 'type' => 'float'],
            ['name' => 'discount_amount', 'type' => 'float'],
            ['name' => 'currency_code', 'type' => 'string', 'facet' => true],
            ['name' => 'payment_method', 'type' => 'string', 'facet' => true],
            ['name' => 'shipping_country', 'type' => 'string', 'facet' => true],
            ['name' => 'shipping_region', 'type' => 'string', 'facet' => true],
            ['name' => 'shipping_city', 'type' => 'string'],
            ['name' => 'shipping_method', 'type' => 'string', 'facet' => true],
            ['name' => 'billing_country', 'type' => 'string', 'facet' => true],
            ['name' => 'billing_region', 'type' => 'string'],
            ['name' => 'item_count', 'type' => 'int32'],
            ['name' => 'item_skus', 'type' => 'string[]'],
            ['name' => 'item_names', 'type' => 'string[]'],
            ['name' => 'created_at', 'type' => 'int64'],
            ['name' => 'updated_at', 'type' => 'int64'],
            ['name' => 'store_id', 'type' => 'int32', 'facet' => true],
        ];

        if ($this->config->isAdminAssistantEnabled()) {
            $fields[] = [
                'name' => 'embedding',
                'type' => 'float[]',
                'embed' => [
                    'from' => ['increment_id', 'customer_name', 'item_names', 'shipping_country', 'status'],
                    'model_config' => [
                        'model_name' => 'ts/all-MiniLM-L12-v2',
                    ],
                ],
            ];
        }

        return $fields;
    }
}
