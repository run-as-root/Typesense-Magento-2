<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;
use RunAsRoot\TypeSense\Model\Indexer\DocumentDeleter;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;
use Typesense\Exceptions\ObjectNotFound;

final class DocumentDeleterTest extends TestCase
{
    private TypeSenseClientFactory&MockObject $clientFactory;
    private Collections&MockObject $collections;
    private LoggerInterface&MockObject $logger;
    private DocumentDeleter $sut;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(TypeSenseClientFactory::class);
        $this->collections = $this->createMock(Collections::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $client = $this->createMock(Client::class);
        $client->collections = $this->collections;
        $this->clientFactory->method('create')->with(3)->willReturn($client);

        $this->sut = new DocumentDeleter($this->clientFactory, $this->logger);
    }

    public function test_deletes_in_chunks_of_100_using_id_filter(): void
    {
        $filters = [];
        $documents = $this->createMock(Documents::class);
        $documents->expects(self::exactly(3))
            ->method('delete')
            ->willReturnCallback(static function (array $params) use (&$filters): array {
                $filters[] = $params['filter_by'];
                return ['num_deleted' => 100];
            });
        $this->stubCollection('rar_product_de', $documents);

        $deleted = $this->sut->deleteByIds('rar_product_de', range(1, 250), 3);

        self::assertSame(300, $deleted);
        self::assertStringStartsWith('id:[1,2,', $filters[0]);
        self::assertStringStartsWith('id:[101,102,', $filters[1]);
        self::assertStringEndsWith('250]', $filters[2]);
    }

    public function test_empty_ids_do_not_call_typesense(): void
    {
        $this->collections->expects(self::never())->method('offsetGet');

        self::assertSame(0, $this->sut->deleteByIds('rar_product_de', [], 3));
    }

    public function test_missing_collection_returns_zero(): void
    {
        $documents = $this->createMock(Documents::class);
        $documents->method('delete')->willThrowException(new ObjectNotFound('not found'));
        $this->stubCollection('rar_product_de', $documents);
        $this->logger->expects(self::never())->method('info');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('error');

        self::assertSame(0, $this->sut->deleteByIds('rar_product_de', ['1'], 3));
    }

    private function stubCollection(string $name, Documents $documents): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->documents = $documents;
        $this->collections->method('offsetGet')->with($name)->willReturn($collection);
    }
}
