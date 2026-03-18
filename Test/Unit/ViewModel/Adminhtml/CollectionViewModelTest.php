<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Adminhtml;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\ViewModel\Adminhtml\CollectionViewModel;
use Typesense\Aliases;
use Typesense\Client;
use Typesense\Collections;

final class CollectionViewModelTest extends TestCase
{
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private LoggerInterface&MockObject $logger;
    private CollectionViewModel $sut;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->sut = new CollectionViewModel(
            $this->clientFactory,
            $this->logger,
        );
    }

    public function test_get_collections_returns_array_on_success(): void
    {
        $expectedCollections = [
            ['name' => 'products_v1', 'num_documents' => 100, 'created_at' => 1700000000],
            ['name' => 'categories_v1', 'num_documents' => 50, 'created_at' => 1700000001],
        ];

        $collections = $this->createMock(Collections::class);
        $collections->method('retrieve')->willReturn($expectedCollections);

        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $this->clientFactory->method('create')->willReturn($client);

        $result = $this->sut->getCollections();

        self::assertSame($expectedCollections, $result);
    }

    public function test_get_collections_returns_empty_array_on_exception(): void
    {
        $collections = $this->createMock(Collections::class);
        $collections->method('retrieve')->willThrowException(new \Exception('Connection refused'));

        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $this->clientFactory->method('create')->willReturn($client);
        $this->logger->expects(self::once())->method('error');

        self::assertSame([], $this->sut->getCollections());
    }

    public function test_get_alias_map_builds_correct_mapping(): void
    {
        $aliasesData = [
            'aliases' => [
                ['name' => 'products', 'collection_name' => 'products_v1'],
                ['name' => 'categories', 'collection_name' => 'categories_v1'],
            ],
        ];

        $aliases = $this->createMock(Aliases::class);
        $aliases->method('retrieve')->willReturn($aliasesData);

        $client = $this->createMock(Client::class);
        $client->aliases = $aliases;

        $this->clientFactory->method('create')->willReturn($client);

        $aliasMap = $this->sut->getAliasMap();

        self::assertSame('products', $aliasMap['products_v1']);
        self::assertSame('categories', $aliasMap['categories_v1']);
    }

    public function test_get_alias_map_returns_empty_array_on_exception(): void
    {
        $aliases = $this->createMock(Aliases::class);
        $aliases->method('retrieve')->willThrowException(new \Exception('API error'));

        $client = $this->createMock(Client::class);
        $client->aliases = $aliases;

        $this->clientFactory->method('create')->willReturn($client);
        $this->logger->expects(self::once())->method('error');

        self::assertSame([], $this->sut->getAliasMap());
    }

    public function test_get_alias_map_skips_entries_with_empty_collection_name(): void
    {
        $aliasesData = [
            'aliases' => [
                ['name' => 'products', 'collection_name' => 'products_v1'],
                ['name' => 'broken_alias', 'collection_name' => ''],
            ],
        ];

        $aliases = $this->createMock(Aliases::class);
        $aliases->method('retrieve')->willReturn($aliasesData);

        $client = $this->createMock(Client::class);
        $client->aliases = $aliases;

        $this->clientFactory->method('create')->willReturn($client);

        $aliasMap = $this->sut->getAliasMap();

        self::assertCount(1, $aliasMap);
        self::assertArrayHasKey('products_v1', $aliasMap);
        self::assertArrayNotHasKey('', $aliasMap);
    }
}
