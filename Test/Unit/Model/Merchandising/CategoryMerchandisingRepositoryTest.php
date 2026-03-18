<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Merchandising;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Data\Collection\AbstractDb as AbstractCollection;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandising;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandisingFactory;
use RunAsRoot\TypeSense\Model\Merchandising\CategoryMerchandisingRepository;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising as CategoryMerchandisingResource;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising\Collection;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising\CollectionFactory;

final class CategoryMerchandisingRepositoryTest extends TestCase
{
    private CategoryMerchandisingFactory&MockObject $factory;
    private CategoryMerchandisingResource&MockObject $resource;
    private CollectionFactory&MockObject $collectionFactory;
    private SearchResultsInterfaceFactory&MockObject $searchResultsFactory;
    private CollectionProcessorInterface&MockObject $collectionProcessor;
    private CategoryMerchandisingRepository $sut;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(CategoryMerchandisingFactory::class);
        $this->resource = $this->createMock(CategoryMerchandisingResource::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(SearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);

        $this->sut = new CategoryMerchandisingRepository(
            $this->factory,
            $this->resource,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
        );
    }

    public function test_get_by_id_returns_entity_when_found(): void
    {
        $entity = $this->createMock(CategoryMerchandising::class);
        $entity->method('getId')->willReturn(1);

        $this->factory->method('create')->willReturn($entity);
        $this->resource->expects(self::once())
            ->method('load')
            ->with($entity, 1);

        $result = $this->sut->getById(1);

        self::assertSame($entity, $result);
    }

    public function test_get_by_id_throws_when_not_found(): void
    {
        $entity = $this->createMock(CategoryMerchandising::class);
        $entity->method('getId')->willReturn(null);

        $this->factory->method('create')->willReturn($entity);
        $this->resource->method('load')->with($entity, 99);

        $this->expectException(NoSuchEntityException::class);

        $this->sut->getById(99);
    }

    public function test_save_persists_entity(): void
    {
        $entity = $this->createMock(CategoryMerchandising::class);

        $this->resource->expects(self::once())
            ->method('save')
            ->with($entity);

        $result = $this->sut->save($entity);

        self::assertSame($entity, $result);
    }

    public function test_save_throws_could_not_save_exception_on_error(): void
    {
        $entity = $this->createMock(CategoryMerchandising::class);

        $this->resource->method('save')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotSaveException::class);

        $this->sut->save($entity);
    }

    public function test_delete_removes_entity(): void
    {
        $entity = $this->createMock(CategoryMerchandising::class);

        $this->resource->expects(self::once())
            ->method('delete')
            ->with($entity);

        $result = $this->sut->delete($entity);

        self::assertTrue($result);
    }

    public function test_delete_throws_could_not_delete_exception_on_error(): void
    {
        $entity = $this->createMock(CategoryMerchandising::class);

        $this->resource->method('delete')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotDeleteException::class);

        $this->sut->delete($entity);
    }

    public function test_get_list_returns_search_results(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $items = [$this->createMock(CategoryMerchandising::class)];

        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('getItems')->willReturn($items);
        $collection->method('getSize')->willReturn(1);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        $this->collectionProcessor->expects(self::once())
            ->method('process')
            ->with($searchCriteria, $collection);

        $searchResults->expects(self::once())->method('setSearchCriteria')->with($searchCriteria);
        $searchResults->expects(self::once())->method('setItems')->with($items);
        $searchResults->expects(self::once())->method('setTotalCount')->with(1);

        $result = $this->sut->getList($searchCriteria);

        self::assertSame($searchResults, $result);
    }
}
