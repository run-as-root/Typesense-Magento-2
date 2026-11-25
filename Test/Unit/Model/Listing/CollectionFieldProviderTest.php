<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Listing;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Listing\CollectionFieldProvider;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;

final class CollectionFieldProviderTest extends TestCase
{
    private Collection&MockObject $collection;
    private CacheInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private CollectionFieldProvider $sut;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('rar_product_de')->willReturn($this->collection);
        $client = $this->createMock(Client::class);
        $client->collections = $collections;
        $clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $clientFactory->method('create')->willReturn($client);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new CollectionFieldProvider($clientFactory, $this->cache, $this->logger);
    }

    public function test_concrete_fields_of_the_collection_behind_the_alias_are_known(): void
    {
        $this->collection->expects(self::once())->method('retrieve')->willReturn(['fields' => [
            ['name' => 'id'],
            ['name' => 'category_position_.*'],
            ['name' => 'category_position_10'],
        ]]);
        $this->cache->expects(self::once())->method('save')
            ->with('["id","category_position_.*","category_position_10"]', 'run_as_root_typesense_fields_rar_product_de', [], 300);

        self::assertTrue($this->sut->hasField('rar_product_de', 'category_position_10'));
        self::assertFalse($this->sut->hasField('rar_product_de', 'category_position_11'));
    }

    public function test_cached_field_names_spare_the_request(): void
    {
        $this->cache->method('load')->willReturn('["category_position_10"]');
        $this->collection->expects(self::never())->method('retrieve');

        self::assertTrue($this->sut->hasField('rar_product_de', 'category_position_10'));
    }

    public function test_unreachable_typesense_means_no_fields_and_is_not_cached(): void
    {
        $this->collection->method('retrieve')->willThrowException(new \RuntimeException('timeout'));
        $this->cache->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('warning');

        self::assertFalse($this->sut->hasField('rar_product_de', 'category_position_10'));
    }
}
