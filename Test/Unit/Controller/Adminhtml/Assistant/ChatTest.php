<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\Assistant;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Model\Assistant\SearchRequestBuilder;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;

/**
 * Tests for the Chat controller focus on its ADMIN_RESOURCE constant and the
 * SearchRequestBuilder collaborator (extracted to allow framework-free unit
 * testing, since Magento\Backend\App\Action\Context cannot be instantiated
 * without a full framework bootstrap).
 */
final class ChatTest extends TestCase
{
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private EntityIndexerPool $fullPool;

    protected function setUp(): void
    {
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);

        $mockIndexer = $this->createMock(EntityIndexerInterface::class);
        $this->fullPool = new EntityIndexerPool([
            'product' => $mockIndexer,
            'category' => $mockIndexer,
            'cms_page' => $mockIndexer,
            'order' => $mockIndexer,
            'customer' => $mockIndexer,
            'store' => $mockIndexer,
            'system_config' => $mockIndexer,
        ]);
    }

    public function test_search_request_builder_includes_all_seven_entity_types_when_all_registered(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturnCallback(
            static fn(string $entityType) => "col_{$entityType}"
        );

        $builder = new SearchRequestBuilder($this->collectionNameResolver, $this->fullPool);
        $requests = $builder->build('default', 1, 'test');

        self::assertCount(7, $requests);
    }

    public function test_search_request_builder_skips_entity_types_without_registered_indexer(): void
    {
        $mockIndexer = $this->createMock(EntityIndexerInterface::class);
        $partialPool = new EntityIndexerPool([
            'product' => $mockIndexer,
            'order' => $mockIndexer,
        ]);

        $this->collectionNameResolver->method('resolve')->willReturnCallback(
            static fn(string $entityType, string $storeCode) => "test_{$entityType}_{$storeCode}"
        );

        $builder = new SearchRequestBuilder($this->collectionNameResolver, $partialPool);
        $requests = $builder->build('default', 1, 'shoes');

        self::assertCount(2, $requests);
        $collections = array_column($requests, 'collection');
        self::assertContains('test_product_default', $collections);
        self::assertContains('test_order_default', $collections);
    }

    public function test_search_request_builder_sets_correct_query_by_for_product(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturnCallback(
            static fn(string $entityType) => "col_{$entityType}"
        );

        $builder = new SearchRequestBuilder($this->collectionNameResolver, $this->fullPool);
        $requests = $builder->build('default', 1, 'blue widget');

        $productRequest = $this->findRequestByCollection($requests, 'col_product');

        self::assertNotNull($productRequest);
        self::assertSame('blue widget', $productRequest['q']);
        self::assertSame('name,description,sku,short_description', $productRequest['query_by']);
    }

    public function test_search_request_builder_sets_correct_query_by_for_order(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturnCallback(
            static fn(string $entityType) => "col_{$entityType}"
        );

        $builder = new SearchRequestBuilder($this->collectionNameResolver, $this->fullPool);
        $requests = $builder->build('default', 1, 'order 000001');

        $orderRequest = $this->findRequestByCollection($requests, 'col_order');

        self::assertNotNull($orderRequest);
        self::assertSame(
            'increment_id,customer_name,customer_email,item_names,shipping_country,status',
            $orderRequest['query_by']
        );
    }

    public function test_search_request_builder_sets_correct_query_by_for_customer(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturnCallback(
            static fn(string $entityType) => "col_{$entityType}"
        );

        $builder = new SearchRequestBuilder($this->collectionNameResolver, $this->fullPool);
        $requests = $builder->build('default', 1, 'john');

        $customerRequest = $this->findRequestByCollection($requests, 'col_customer');

        self::assertNotNull($customerRequest);
        self::assertSame(
            'email,firstname,lastname,group_name,default_shipping_country',
            $customerRequest['query_by']
        );
    }

    public function test_search_request_builder_passes_store_code_and_store_id_to_resolver(): void
    {
        $this->collectionNameResolver
            ->expects(self::atLeastOnce())
            ->method('resolve')
            ->with(
                self::isType('string'),
                'en_gb',
                2,
            )
            ->willReturn('some_collection');

        $builder = new SearchRequestBuilder($this->collectionNameResolver, $this->fullPool);
        $builder->build('en_gb', 2, 'test query');
    }

    public function test_search_request_builder_returns_empty_array_when_no_indexers_registered(): void
    {
        $builder = new SearchRequestBuilder($this->collectionNameResolver, new EntityIndexerPool([]));
        $requests = $builder->build('default', 1, 'anything');

        self::assertSame([], $requests);
    }

    public function test_search_request_builder_forwards_query_to_all_requests(): void
    {
        $this->collectionNameResolver->method('resolve')->willReturnCallback(
            static fn(string $entityType) => "col_{$entityType}"
        );

        $builder = new SearchRequestBuilder($this->collectionNameResolver, $this->fullPool);
        $requests = $builder->build('default', 1, 'my specific query');

        foreach ($requests as $request) {
            self::assertSame('my specific query', $request['q']);
        }
    }

    /** @param array<int, array<string, mixed>> $requests */
    private function findRequestByCollection(array $requests, string $collection): ?array
    {
        foreach ($requests as $request) {
            if ($request['collection'] === $collection) {
                return $request;
            }
        }

        return null;
    }
}
