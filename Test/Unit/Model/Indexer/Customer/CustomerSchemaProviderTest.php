<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Customer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Customer\CustomerSchemaProvider;

final class CustomerSchemaProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private CustomerSchemaProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new CustomerSchemaProvider($this->config);
    }

    public function test_get_fields_returns_core_fields(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('id', $fieldNames);
        self::assertContains('customer_id', $fieldNames);
        self::assertContains('email', $fieldNames);
        self::assertContains('firstname', $fieldNames);
        self::assertContains('lastname', $fieldNames);
        self::assertContains('group_id', $fieldNames);
        self::assertContains('group_name', $fieldNames);
        self::assertContains('created_at', $fieldNames);
        self::assertContains('updated_at', $fieldNames);
        self::assertContains('dob', $fieldNames);
        self::assertContains('gender', $fieldNames);
        self::assertContains('default_billing_country', $fieldNames);
        self::assertContains('default_billing_region', $fieldNames);
        self::assertContains('default_billing_city', $fieldNames);
        self::assertContains('default_shipping_country', $fieldNames);
        self::assertContains('default_shipping_region', $fieldNames);
        self::assertContains('default_shipping_city', $fieldNames);
        self::assertContains('order_count', $fieldNames);
        self::assertContains('lifetime_value', $fieldNames);
        self::assertContains('last_order_date', $fieldNames);
        self::assertContains('store_id', $fieldNames);
        self::assertContains('website_id', $fieldNames);
        self::assertContains('is_active', $fieldNames);
    }

    public function test_get_fields_omits_embedding_when_admin_assistant_disabled(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertNotContains('embedding', $fieldNames);
    }

    public function test_get_fields_includes_embedding_when_admin_assistant_enabled(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(true);

        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('embedding', $fieldNames);
    }

    public function test_embedding_field_has_correct_model_config(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(true);

        $fields = $this->sut->getFields();

        $embeddingField = null;
        foreach ($fields as $field) {
            if ($field['name'] === 'embedding') {
                $embeddingField = $field;
                break;
            }
        }

        self::assertNotNull($embeddingField);
        self::assertSame('float[]', $embeddingField['type']);
        self::assertSame('ts/all-MiniLM-L12-v2', $embeddingField['embed']['model_config']['model_name']);
        self::assertContains('email', $embeddingField['embed']['from']);
        self::assertContains('firstname', $embeddingField['embed']['from']);
        self::assertContains('lastname', $embeddingField['embed']['from']);
        self::assertContains('group_name', $embeddingField['embed']['from']);
    }

    public function test_optional_fields_have_optional_true(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();

        $optionalFields = array_filter($fields, static fn(array $f): bool => ($f['optional'] ?? false) === true);
        $optionalNames = array_column(array_values($optionalFields), 'name');

        self::assertContains('dob', $optionalNames);
        self::assertContains('gender', $optionalNames);
        self::assertContains('default_billing_country', $optionalNames);
        self::assertContains('default_billing_region', $optionalNames);
        self::assertContains('default_billing_city', $optionalNames);
        self::assertContains('default_shipping_country', $optionalNames);
        self::assertContains('default_shipping_region', $optionalNames);
        self::assertContains('default_shipping_city', $optionalNames);
        self::assertContains('last_order_date', $optionalNames);
    }

    public function test_facet_fields_have_facet_true(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();

        $facetFields = array_filter($fields, static fn(array $f): bool => ($f['facet'] ?? false) === true);
        $facetNames = array_column(array_values($facetFields), 'name');

        self::assertContains('group_name', $facetNames);
        self::assertContains('gender', $facetNames);
        self::assertContains('default_billing_country', $facetNames);
        self::assertContains('default_billing_region', $facetNames);
        self::assertContains('default_shipping_country', $facetNames);
        self::assertContains('default_shipping_region', $facetNames);
        self::assertContains('store_id', $facetNames);
        self::assertContains('website_id', $facetNames);
        self::assertContains('is_active', $facetNames);
    }
}
