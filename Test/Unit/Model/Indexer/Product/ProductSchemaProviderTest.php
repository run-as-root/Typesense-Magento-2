<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProvider;

final class ProductSchemaProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private ProductSchemaProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $this->sut = new ProductSchemaProvider($this->config);
    }

    public function test_get_fields_returns_array(): void
    {
        $fields = $this->sut->getFields();

        self::assertIsArray($fields);
        self::assertNotEmpty($fields);
    }

    public function test_get_fields_contains_id_field(): void
    {
        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('id', $fieldNames);

        $idField = $this->findField($fields, 'id');
        self::assertSame('string', $idField['type']);
    }

    public function test_get_fields_contains_product_id_as_int32(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'product_id');
        self::assertSame('int32', $field['type']);
    }

    public function test_get_fields_contains_name_as_string(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'name');
        self::assertSame('string', $field['type']);
    }

    public function test_get_fields_contains_sku_as_string(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'sku');
        self::assertSame('string', $field['type']);
    }

    public function test_get_fields_contains_price_as_float(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'price');
        self::assertSame('float', $field['type']);
    }

    public function test_get_fields_contains_special_price_as_optional_float(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'special_price');
        self::assertSame('float', $field['type']);
        self::assertTrue($field['optional']);
    }

    public function test_get_fields_contains_categories_as_string_array(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'categories');
        self::assertSame('string[]', $field['type']);
    }

    public function test_get_fields_contains_category_ids_as_int32_array(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'category_ids');
        self::assertSame('int32[]', $field['type']);
    }

    public function test_get_fields_contains_hierarchical_category_facets(): void
    {
        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('categories.lvl0', $fieldNames);
        self::assertContains('categories.lvl1', $fieldNames);
        self::assertContains('categories.lvl2', $fieldNames);

        foreach (['categories.lvl0', 'categories.lvl1', 'categories.lvl2'] as $facetName) {
            $field = $this->findField($fields, $facetName);
            self::assertSame('string[]', $field['type']);
            self::assertTrue($field['facet'], "Field {$facetName} should be a facet");
        }
    }

    public function test_get_fields_contains_in_stock_as_bool_facet(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'in_stock');
        self::assertSame('bool', $field['type']);
        self::assertTrue($field['facet']);
    }

    public function test_get_fields_contains_type_id_as_string_facet(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'type_id');
        self::assertSame('string', $field['type']);
        self::assertTrue($field['facet']);
    }

    public function test_get_fields_contains_visibility_as_int32(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'visibility');
        self::assertSame('int32', $field['type']);
    }

    public function test_get_fields_contains_created_at_as_int64(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'created_at');
        self::assertSame('int64', $field['type']);
    }

    public function test_get_fields_contains_updated_at_as_int64(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'updated_at');
        self::assertSame('int64', $field['type']);
    }

    public function test_get_fields_contains_description_as_optional_string(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'description');
        self::assertSame('string', $field['type']);
        self::assertTrue($field['optional']);
    }

    public function test_get_fields_contains_short_description_as_optional_string(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'short_description');
        self::assertSame('string', $field['type']);
        self::assertTrue($field['optional']);
    }

    public function test_get_fields_contains_url_as_string(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'url');
        self::assertSame('string', $field['type']);
    }

    public function test_get_fields_contains_image_url_as_string(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'image_url');
        self::assertSame('string', $field['type']);
    }

    public function test_get_fields_contains_sales_count_as_int32(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'sales_count');
        self::assertSame('int32', $field['type']);
    }

    public function test_get_fields_contains_rating_summary_as_int32(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'rating_summary');
        self::assertSame('int32', $field['type']);
    }

    public function test_get_fields_contains_review_count_as_int32(): void
    {
        $fields = $this->sut->getFields();

        $field = $this->findField($fields, 'review_count');
        self::assertSame('int32', $field['type']);
    }

    public function test_get_fields_includes_additional_attributes_as_optional_facet_strings(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(['color', 'size']);

        $sut = new ProductSchemaProvider($config);
        $fields = $sut->getFields();

        $colorField = $this->findField($fields, 'color');
        self::assertSame('string', $colorField['type']);
        self::assertTrue($colorField['optional']);
        self::assertTrue($colorField['facet']);

        $sizeField = $this->findField($fields, 'size');
        self::assertSame('string', $sizeField['type']);
        self::assertTrue($sizeField['optional']);
        self::assertTrue($sizeField['facet']);
    }

    public function test_get_fields_returns_no_additional_attribute_fields_when_none_configured(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn([]);

        $sut = new ProductSchemaProvider($config);
        $fieldNames = array_column($sut->getFields(), 'name');

        $coreNames = ['id', 'product_id', 'name', 'sku', 'url', 'image_url', 'price',
            'special_price', 'description', 'short_description', 'categories', 'category_ids',
            'categories.lvl0', 'categories.lvl1', 'categories.lvl2', 'in_stock', 'type_id',
            'visibility', 'created_at', 'updated_at', 'sales_count', 'rating_summary', 'review_count',
            'categories_text'];

        self::assertSame($coreNames, $fieldNames);
    }

    public function test_get_fields_includes_embedding_when_conversational_enabled(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isConversationalSearchEnabled')->willReturn(true);
        $this->config->method('getEmbeddingFields')->willReturn(['name', 'description']);
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $sut = new ProductSchemaProvider($this->config);
        $fields = $sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('embedding', $fieldNames);

        $embeddingField = array_values(array_filter($fields, fn($f) => $f['name'] === 'embedding'))[0];
        self::assertSame('float[]', $embeddingField['type']);
        self::assertSame(['name', 'description'], $embeddingField['embed']['from']);
    }

    public function test_get_fields_excludes_embedding_when_conversational_disabled(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('isConversationalSearchEnabled')->willReturn(false);
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $sut = new ProductSchemaProvider($this->config);
        $fields = $sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertNotContains('embedding', $fieldNames);
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function findField(array $fields, string $name): array
    {
        foreach ($fields as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        self::fail("Field '{$name}' not found in schema fields");
    }
}
