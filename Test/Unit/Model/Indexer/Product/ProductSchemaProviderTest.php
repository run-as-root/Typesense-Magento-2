<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProvider;

final class ProductSchemaProviderTest extends TestCase
{
    private ProductSchemaProvider $sut;

    protected function setUp(): void
    {
        $this->sut = new ProductSchemaProvider();
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
