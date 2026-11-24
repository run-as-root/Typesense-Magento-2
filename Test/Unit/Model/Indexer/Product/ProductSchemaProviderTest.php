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

    public function test_get_fields_contains_final_and_regular_price_as_float_without_special_price(): void
    {
        $fields = $this->sut->getFields();

        self::assertSame('float', $this->findField($fields, 'final_price')['type']);
        self::assertSame('float', $this->findField($fields, 'regular_price')['type']);
        self::assertNotContains('special_price', array_column($fields, 'name'));
    }

    public function test_schema_enables_nested_fields_and_declares_children_unindexed(): void
    {
        $field = $this->findField($this->sut->getFields(), 'children');

        self::assertSame(['enable_nested_fields' => true], $this->sut->getCollectionSettings());
        self::assertSame('object[]', $field['type']);
        self::assertTrue($field['optional']);
        self::assertFalse($field['index']);
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

    public function test_is_new_is_an_optional_bool_facet_for_the_new_badge(): void
    {
        $field = $this->findField($this->sut->getFields(), 'is_new');

        self::assertSame('bool', $field['type']);
        self::assertTrue($field['facet'] ?? false);
        self::assertTrue($field['optional'] ?? false);
    }

    public function test_price_is_a_float_facet_for_the_price_slider_stats(): void
    {
        $field = $this->findField($this->sut->getFields(), 'price');

        self::assertSame('float', $field['type']);
        self::assertTrue($field['facet'] ?? false);
    }

    public function test_in_stock_is_sortable_for_out_of_stock_last_listings(): void
    {
        $field = $this->findField($this->sut->getFields(), 'in_stock');

        self::assertTrue($field['sort'] ?? false);
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

    public function test_get_fields_includes_additional_attributes_as_optional_facet_string_arrays(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(['color', 'size']);

        $sut = new ProductSchemaProvider($config);
        $fields = $sut->getFields();

        // Composite parents hold their children's values too, so even a single select can have several.
        $colorField = $this->findField($fields, 'color');
        self::assertSame('string[]', $colorField['type']);
        self::assertTrue($colorField['optional']);
        self::assertTrue($colorField['facet']);

        $sizeField = $this->findField($fields, 'size');
        self::assertSame('string[]', $sizeField['type']);
        self::assertTrue($sizeField['optional']);
        self::assertTrue($sizeField['facet']);
    }

    public function test_additional_attributes_named_like_core_fields_do_not_duplicate_them(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(['short_description', 'color', 'price']);

        $sut = new ProductSchemaProvider($config);
        $fields = $sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertSame($fieldNames, array_values(array_unique($fieldNames)));
        self::assertSame('string', $this->findField($fields, 'short_description')['type']);
        self::assertSame('float', $this->findField($fields, 'price')['type']);
        self::assertContains('color', $fieldNames);
    }

    public function test_additional_attributes_named_like_late_core_fields_do_not_duplicate_them(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(
            ['categories_text', 'name_decompounded', 'embedding', 'is_new', 'category_ids', 'children', 'children_text', 'color'],
        );
        $config->method('isGermanDecompoundingEnabled')->willReturn(true);
        $config->method('isConversationalSearchEnabled')->willReturn(true);
        $config->method('getEmbeddingFields')->willReturn(['name']);

        $fields = (new ProductSchemaProvider($config))->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertSame($fieldNames, array_values(array_unique($fieldNames)));
        self::assertSame('bool', $this->findField($fields, 'is_new')['type']);
        self::assertContains('color', $fieldNames);
    }

    public function test_reserved_fields_cover_every_core_field_with_all_features_enabled(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn([]);
        $config->method('isGermanDecompoundingEnabled')->willReturn(true);
        $config->method('isConversationalSearchEnabled')->willReturn(true);
        $config->method('getEmbeddingFields')->willReturn(['name']);

        $fieldNames = array_column((new ProductSchemaProvider($config))->getFields(), 'name');

        self::assertEqualsCanonicalizing($fieldNames, ProductSchemaProvider::RESERVED_FIELDS);
    }

    public function test_get_fields_returns_no_additional_attribute_fields_when_none_configured(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn([]);

        $sut = new ProductSchemaProvider($config);
        $fieldNames = array_column($sut->getFields(), 'name');

        $coreNames = ['id', 'product_id', 'name', 'sku', 'url', 'image_url', 'price',
            'final_price', 'regular_price', 'description', 'short_description', 'categories', 'category_ids',
            'categories.lvl0', 'categories.lvl1', 'categories.lvl2', 'in_stock', 'is_new', 'type_id',
            'visibility', 'created_at', 'updated_at', 'sales_count', 'rating_summary', 'review_count',
            'children', 'children_text', 'categories_text'];

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

    public function test_only_full_text_searchable_fields_get_stem_flag_when_enabled(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn(['color']);
        $config->method('isStemEnabled')->willReturn(true);
        $config->method('isGermanDecompoundingEnabled')->willReturn(true);
        $fields = (new ProductSchemaProvider($config))->getFields();

        $stemmed = ['name', 'description', 'short_description', 'categories_text', 'name_decompounded', 'children_text'];
        foreach ($stemmed as $name) {
            self::assertTrue($this->findField($fields, $name)['stem'] ?? false, "{$name} should stem");
        }

        $exact = ['id', 'sku', 'url', 'image_url', 'type_id', 'categories', 'categories.lvl0', 'categories.lvl1',
            'categories.lvl2', 'color', 'price', 'product_id', 'category_ids', 'in_stock'];
        foreach ($exact as $name) {
            self::assertArrayNotHasKey('stem', $this->findField($fields, $name), "{$name} should not stem");
        }
    }

    public function test_stem_flag_absent_when_disabled(): void
    {
        $fields = $this->sut->getFields();

        self::assertArrayNotHasKey('stem', $this->findField($fields, 'name'));
    }

    public function test_infix_flag_only_on_configured_fields(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn([]);
        $config->method('isInfixEnabled')->willReturn(true);
        $config->method('getInfixFields')->willReturn(['name', 'price']);
        $fields = (new ProductSchemaProvider($config))->getFields();

        self::assertTrue($this->findField($fields, 'name')['infix']);
        self::assertArrayNotHasKey('infix', $this->findField($fields, 'sku'));
        self::assertArrayNotHasKey('infix', $this->findField($fields, 'price'));
    }

    public function test_infix_flag_absent_when_disabled(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn([]);
        $config->method('isInfixEnabled')->willReturn(false);
        $config->method('getInfixFields')->willReturn(['name']);
        $fields = (new ProductSchemaProvider($config))->getFields();

        self::assertArrayNotHasKey('infix', $this->findField($fields, 'name'));
    }

    public function test_schema_has_name_decompounded_only_when_enabled(): void
    {
        self::assertNotContains('name_decompounded', array_column($this->sut->getFields(), 'name'));

        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn([]);
        $config->method('isGermanDecompoundingEnabled')->willReturn(true);
        $fields = (new ProductSchemaProvider($config))->getFields();

        self::assertSame(
            ['name' => 'name_decompounded', 'type' => 'string', 'optional' => true],
            $this->findField($fields, 'name_decompounded'),
        );
    }

    public function test_name_decompounded_gets_stem_and_infix_flags(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getAdditionalAttributes')->willReturn([]);
        $config->method('isGermanDecompoundingEnabled')->willReturn(true);
        $config->method('isStemEnabled')->willReturn(true);
        $config->method('isInfixEnabled')->willReturn(true);
        $config->method('getInfixFields')->willReturn(['name_decompounded']);

        $field = $this->findField((new ProductSchemaProvider($config))->getFields(), 'name_decompounded');

        self::assertTrue($field['stem']);
        self::assertTrue($field['infix']);
    }

    public function test_children_text_is_an_optional_searchable_string_array(): void
    {
        self::assertSame(
            ['name' => 'children_text', 'type' => 'string[]', 'optional' => true],
            $this->findField($this->sut->getFields(), 'children_text'),
        );
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
