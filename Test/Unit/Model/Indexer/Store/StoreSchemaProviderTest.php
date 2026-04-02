<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Store;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Store\StoreSchemaProvider;

final class StoreSchemaProviderTest extends TestCase
{
    private StoreSchemaProvider $sut;

    protected function setUp(): void
    {
        $this->sut = new StoreSchemaProvider();
    }

    public function test_get_fields_returns_all_required_fields(): void
    {
        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('id', $fieldNames);
        self::assertContains('store_id', $fieldNames);
        self::assertContains('store_code', $fieldNames);
        self::assertContains('store_name', $fieldNames);
        self::assertContains('website_id', $fieldNames);
        self::assertContains('website_code', $fieldNames);
        self::assertContains('website_name', $fieldNames);
        self::assertContains('group_id', $fieldNames);
        self::assertContains('group_name', $fieldNames);
        self::assertContains('root_category_id', $fieldNames);
        self::assertContains('base_url', $fieldNames);
        self::assertContains('base_currency', $fieldNames);
        self::assertContains('default_locale', $fieldNames);
        self::assertContains('is_active', $fieldNames);
    }

    public function test_is_active_field_has_facet_true(): void
    {
        $fields = $this->sut->getFields();

        $isActiveField = null;
        foreach ($fields as $field) {
            if ($field['name'] === 'is_active') {
                $isActiveField = $field;
                break;
            }
        }

        self::assertNotNull($isActiveField);
        self::assertTrue($isActiveField['facet'] ?? false);
    }

    public function test_store_id_field_is_int32(): void
    {
        $fields = $this->sut->getFields();

        $storeIdField = null;
        foreach ($fields as $field) {
            if ($field['name'] === 'store_id') {
                $storeIdField = $field;
                break;
            }
        }

        self::assertNotNull($storeIdField);
        self::assertSame('int32', $storeIdField['type']);
    }

    public function test_id_field_is_string(): void
    {
        $fields = $this->sut->getFields();

        $idField = null;
        foreach ($fields as $field) {
            if ($field['name'] === 'id') {
                $idField = $field;
                break;
            }
        }

        self::assertNotNull($idField);
        self::assertSame('string', $idField['type']);
    }

    public function test_get_fields_returns_no_embedding_field(): void
    {
        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertNotContains('embedding', $fieldNames);
    }
}
