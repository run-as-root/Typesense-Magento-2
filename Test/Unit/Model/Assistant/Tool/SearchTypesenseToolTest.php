<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Assistant\Tool\SearchTypesenseTool;

final class SearchTypesenseToolTest extends TestCase
{
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private StoreManagerInterface&MockObject $storeManager;
    private SearchTypesenseTool $sut;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->sut = new SearchTypesenseTool(
            $this->clientFactory,
            $this->collectionNameResolver,
            $this->storeManager,
        );
    }

    public function test_get_name_returns_search_typesense(): void
    {
        self::assertSame('search_typesense', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_required_fields(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('required', $schema);
        self::assertContains('collection', $schema['required']);
        self::assertContains('query', $schema['required']);
    }

    public function test_get_parameters_schema_collection_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $collectionSchema = $schema['properties']['collection'];

        self::assertArrayHasKey('enum', $collectionSchema);
        self::assertContains('product', $collectionSchema['enum']);
        self::assertContains('order', $collectionSchema['enum']);
        self::assertContains('customer', $collectionSchema['enum']);
        self::assertContains('category', $collectionSchema['enum']);
        self::assertContains('cms_page', $collectionSchema['enum']);
    }

    public function test_get_parameters_schema_has_optional_filter_sort_limit(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('filter_by', $schema['properties']);
        self::assertArrayHasKey('sort_by', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertSame('integer', $schema['properties']['limit']['type']);
    }

    public function test_execute_returns_error_for_unknown_collection(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $this->storeManager->method('getDefaultStoreView')->willReturn($store);

        $result = json_decode($this->sut->execute(['collection' => 'invalid', 'query' => 'test']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('invalid', $result['error']);
    }
}
