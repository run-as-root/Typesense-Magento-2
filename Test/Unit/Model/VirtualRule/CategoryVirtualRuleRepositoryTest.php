<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule\Collection;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule\CollectionFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRule;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleRepository;

final class CategoryVirtualRuleRepositoryTest extends TestCase
{
    private CategoryVirtualRuleFactory&MockObject $factory;
    private CategoryVirtualRuleResource&MockObject $resource;
    private CollectionFactory&MockObject $collectionFactory;
    private SearchResultsInterfaceFactory&MockObject $searchResultsFactory;
    private CollectionProcessorInterface&MockObject $collectionProcessor;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private CategoryVirtualRuleRepository $sut;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(CategoryVirtualRuleFactory::class);
        $this->resource = $this->createMock(CategoryVirtualRuleResource::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(SearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);

        $this->sut = new CategoryVirtualRuleRepository(
            $this->factory,
            $this->resource,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
            $this->searchCriteriaBuilder,
        );
    }

    public function test_get_by_id_returns_entity_when_found(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);
        $entity->method('getId')->willReturn(1);

        $this->factory->method('create')->willReturn($entity);
        $this->resource->expects(self::once())->method('load')->with($entity, 1);

        self::assertSame($entity, $this->sut->getById(1));
    }

    public function test_get_by_id_throws_when_not_found(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);
        $entity->method('getId')->willReturn(null);

        $this->factory->method('create')->willReturn($entity);
        $this->resource->method('load')->with($entity, 99);

        $this->expectException(NoSuchEntityException::class);

        $this->sut->getById(99);
    }

    public function test_save_persists_entity(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->expects(self::once())->method('save')->with($entity);

        self::assertSame($entity, $this->sut->save($entity));
    }

    public function test_save_throws_could_not_save_exception_on_error(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->method('save')->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotSaveException::class);

        $this->sut->save($entity);
    }

    public function test_delete_removes_entity(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->expects(self::once())->method('delete')->with($entity);

        self::assertTrue($this->sut->delete($entity));
    }

    public function test_delete_throws_could_not_delete_exception_on_error(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->method('delete')->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotDeleteException::class);

        $this->sut->delete($entity);
    }

    public function test_get_list_returns_search_results(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $items = [$this->createMock(CategoryVirtualRule::class)];

        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('getItems')->willReturn($items);
        $collection->method('getSize')->willReturn(1);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        $this->collectionProcessor->expects(self::once())->method('process')->with($searchCriteria, $collection);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($searchCriteria);
        $searchResults->expects(self::once())->method('setItems')->with($items);
        $searchResults->expects(self::once())->method('setTotalCount')->with(1);

        self::assertSame($searchResults, $this->sut->getList($searchCriteria));
    }

    public function test_find_by_category_and_store_returns_first_match(): void
    {
        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('getItems')->willReturn([$rule]);
        $collection->method('getSize')->willReturn(1);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);
        $searchResults->method('getItems')->willReturn([$rule]);

        self::assertSame($rule, $this->sut->findByCategoryAndStore(10, 1));
    }

    public function test_find_by_category_and_store_returns_null_when_no_match(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('getItems')->willReturn([]);
        $collection->method('getSize')->willReturn(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);
        $searchResults->method('getItems')->willReturn([]);

        self::assertNull($this->sut->findByCategoryAndStore(10, 1));
    }
}
