<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Suggestion;

use Magento\Search\Model\Query;
use Magento\Search\Model\ResourceModel\Query\Collection as QueryCollection;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory as QueryCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Suggestion\SuggestionDataBuilder;

final class SuggestionDataBuilderTest extends TestCase
{
    private QueryCollectionFactory&MockObject $collectionFactory;
    private SuggestionDataBuilder $sut;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(QueryCollectionFactory::class);

        $this->sut = new SuggestionDataBuilder(
            $this->collectionFactory,
        );
    }

    public function test_build_returns_document_with_core_fields(): void
    {
        $query = $this->createQueryMock(
            id: 42,
            queryText: 'blue shoes',
            numResults: 15,
            popularity: 7,
        );

        $document = $this->sut->build($query, 1);

        self::assertSame('42', $document['id']);
        self::assertSame('blue shoes', $document['query']);
        self::assertSame(15, $document['num_results']);
        self::assertSame(7, $document['popularity']);
    }

    public function test_get_query_collection_filters_num_results_greater_than_zero(): void
    {
        $collection = $this->createMock(QueryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection): QueryCollection {
                return $collection;
            });

        $this->sut->getQueryCollection([], 1);
    }

    public function test_get_query_collection_filters_by_store_id(): void
    {
        $collection = $this->createMock(QueryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $calls = [];
        $collection->expects(self::exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection, &$calls): QueryCollection {
                $calls[] = [$field, $condition];
                return $collection;
            });

        $this->sut->getQueryCollection([], 2);

        self::assertSame('store_id', $calls[0][0]);
        self::assertSame(2, $calls[0][1]);
    }

    public function test_get_query_collection_filters_by_entity_ids_when_provided(): void
    {
        $collection = $this->createMock(QueryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collection->expects(self::exactly(3))
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection): QueryCollection {
                return $collection;
            });

        $this->sut->getQueryCollection([10, 20, 30], 1);
    }

    public function test_get_query_collection_does_not_filter_entity_ids_when_empty(): void
    {
        $collection = $this->createMock(QueryCollection::class);
        $this->collectionFactory->method('create')->willReturn($collection);

        // Only store_id and num_results filters — no query_id filter
        $collection->expects(self::exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) use ($collection): QueryCollection {
                return $collection;
            });

        $this->sut->getQueryCollection([], 1);
    }

    /**
     * @return Query&MockObject
     */
    private function createQueryMock(
        int $id = 1,
        string $queryText = 'test query',
        int $numResults = 5,
        int $popularity = 3,
    ): Query&MockObject {
        // getNumResults and getPopularity are magic __call getters; use addMethods() for them
        // and onlyMethods() for real parent methods like getId and getQueryText
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getQueryText'])
            ->addMethods(['getNumResults', 'getPopularity'])
            ->getMock();

        $query->method('getId')->willReturn($id);
        $query->method('getQueryText')->willReturn($queryText);
        $query->method('getNumResults')->willReturn($numResults);
        $query->method('getPopularity')->willReturn($popularity);

        return $query;
    }
}
