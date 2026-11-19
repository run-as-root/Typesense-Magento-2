<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Collection\Applier;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\Collection\Applier\CategoryMerchandisingApplier;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;

final class CategoryMerchandisingApplierTest extends TestCase
{
    private CategoryMerchandisingRepositoryInterface&MockObject $repository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private CategoryMerchandisingSync&MockObject $sync;
    private LoggerInterface&MockObject $logger;
    private CategoryMerchandisingApplier $sut;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CategoryMerchandisingRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sync = $this->createMock(CategoryMerchandisingSync::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new CategoryMerchandisingApplier(
            $this->repository,
            $this->searchCriteriaBuilder,
            $this->sync,
            $this->logger,
        );
    }

    public function test_syncs_each_distinct_category_of_the_store_into_new_collection(): void
    {
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $this->searchCriteriaBuilder->expects(self::once())
            ->method('addFilter')
            ->with('store_id', 3)
            ->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($criteria);

        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn([$this->row(5), $this->row(7), $this->row(5)]);
        $this->repository->method('getList')->with($criteria)->willReturn($results);

        $synced = [];
        $this->sync->expects(self::exactly(2))
            ->method('sync')
            ->willReturnCallback(static function (...$args) use (&$synced): void {
                $synced[] = $args;
            });

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v2');

        self::assertSame([[5, 3, 'de', 'rar_product_de_v2'], [7, 3, 'de', 'rar_product_de_v2']], $synced);
    }

    public function test_skips_non_product_entities(): void
    {
        $this->repository->expects(self::never())->method('getList');
        $this->sync->expects(self::never())->method('sync');

        $this->sut->apply('cms_page', 3, 'de', 'rar_cms_page_de_v2');
    }

    public function test_failing_category_does_not_stop_remaining_categories(): void
    {
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteriaInterface::class));
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn([$this->row(5), $this->row(7)]);
        $this->repository->method('getList')->willReturn($results);

        $this->sync->expects(self::exactly(2))
            ->method('sync')
            ->willReturnCallback(static function (int $categoryId): void {
                if ($categoryId === 5) {
                    throw new \RuntimeException('typesense down');
                }
            });
        $this->logger->expects(self::once())->method('error');

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v2');
    }

    private function row(int $categoryId): CategoryMerchandisingInterface&MockObject
    {
        $row = $this->createMock(CategoryMerchandisingInterface::class);
        $row->method('getCategoryId')->willReturn($categoryId);

        return $row;
    }
}
