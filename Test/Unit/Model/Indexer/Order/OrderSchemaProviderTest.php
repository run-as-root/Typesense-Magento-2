<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Order;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Order\OrderSchemaProvider;

final class OrderSchemaProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private OrderSchemaProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new OrderSchemaProvider($this->config);
    }

    public function test_get_fields_returns_core_fields(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();

        $fieldNames = array_column($fields, 'name');

        self::assertContains('id', $fieldNames);
        self::assertContains('order_id', $fieldNames);
        self::assertContains('increment_id', $fieldNames);
        self::assertContains('status', $fieldNames);
        self::assertContains('state', $fieldNames);
        self::assertContains('customer_email', $fieldNames);
        self::assertContains('customer_name', $fieldNames);
        self::assertContains('customer_group', $fieldNames);
        self::assertContains('grand_total', $fieldNames);
        self::assertContains('subtotal', $fieldNames);
        self::assertContains('tax_amount', $fieldNames);
        self::assertContains('shipping_amount', $fieldNames);
        self::assertContains('discount_amount', $fieldNames);
        self::assertContains('currency_code', $fieldNames);
        self::assertContains('payment_method', $fieldNames);
        self::assertContains('shipping_country', $fieldNames);
        self::assertContains('shipping_region', $fieldNames);
        self::assertContains('shipping_city', $fieldNames);
        self::assertContains('shipping_method', $fieldNames);
        self::assertContains('billing_country', $fieldNames);
        self::assertContains('billing_region', $fieldNames);
        self::assertContains('item_count', $fieldNames);
        self::assertContains('item_skus', $fieldNames);
        self::assertContains('item_names', $fieldNames);
        self::assertContains('created_at', $fieldNames);
        self::assertContains('updated_at', $fieldNames);
        self::assertContains('store_id', $fieldNames);
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
        self::assertContains('increment_id', $embeddingField['embed']['from']);
        self::assertContains('customer_name', $embeddingField['embed']['from']);
        self::assertContains('item_names', $embeddingField['embed']['from']);
        self::assertContains('status', $embeddingField['embed']['from']);
    }

    public function test_facet_fields_have_facet_true(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();

        $facetFields = array_filter($fields, static fn(array $f): bool => ($f['facet'] ?? false) === true);
        $facetNames = array_column(array_values($facetFields), 'name');

        self::assertContains('status', $facetNames);
        self::assertContains('state', $facetNames);
        self::assertContains('customer_group', $facetNames);
        self::assertContains('currency_code', $facetNames);
        self::assertContains('payment_method', $facetNames);
        self::assertContains('shipping_country', $facetNames);
        self::assertContains('store_id', $facetNames);
    }
}
