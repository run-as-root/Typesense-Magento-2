<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Customer;

use RunAsRoot\TypeSense\Api\CustomerSchemaProviderInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class CustomerSchemaProvider implements CustomerSchemaProviderInterface
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
            ['name' => 'customer_id', 'type' => 'int32'],
            ['name' => 'email', 'type' => 'string'],
            ['name' => 'firstname', 'type' => 'string'],
            ['name' => 'lastname', 'type' => 'string'],
            ['name' => 'group_id', 'type' => 'int32'],
            ['name' => 'group_name', 'type' => 'string', 'facet' => true],
            ['name' => 'created_at', 'type' => 'int64'],
            ['name' => 'updated_at', 'type' => 'int64'],
            ['name' => 'dob', 'type' => 'string', 'optional' => true],
            ['name' => 'gender', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_billing_country', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_billing_region', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_billing_city', 'type' => 'string', 'optional' => true],
            ['name' => 'default_shipping_country', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_shipping_region', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_shipping_city', 'type' => 'string', 'optional' => true],
            ['name' => 'order_count', 'type' => 'int32'],
            ['name' => 'lifetime_value', 'type' => 'float'],
            ['name' => 'last_order_date', 'type' => 'int64', 'optional' => true],
            ['name' => 'store_id', 'type' => 'int32', 'facet' => true],
            ['name' => 'website_id', 'type' => 'int32', 'facet' => true],
            ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
        ];

        if ($this->config->isAdminAssistantEnabled()) {
            $fields[] = [
                'name' => 'embedding',
                'type' => 'float[]',
                'embed' => [
                    'from' => ['email', 'firstname', 'lastname', 'group_name'],
                    'model_config' => [
                        'model_name' => 'ts/all-MiniLM-L12-v2',
                    ],
                ],
            ];
        }

        return $fields;
    }
}
