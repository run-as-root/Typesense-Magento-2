<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Collection\Applier;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\Data\QueryMerchandisingInterface;
use RunAsRoot\TypeSense\Api\QueryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Model\Collection\Applier\QueryMerchandisingApplier;
use RunAsRoot\TypeSense\Model\Curation\QueryMerchandisingSync;

final class QueryMerchandisingApplierTest extends TestCase
{
    private QueryMerchandisingRepositoryInterface&MockObject $repository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private QueryMerchandisingSync&MockObject $sync;
    private LoggerInterface&MockObject $logger;
    private QueryMerchandisingApplier $sut;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(QueryMerchandisingRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sync = $this->createMock(QueryMerchandisingSync::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new QueryMerchandisingApplier(
            $this->repository,
            $this->searchCriteriaBuilder,
            $this->sync,
            $this->logger,
        );
    }

    public function test_syncs_each_query_rule_of_the_store_into_new_collection(): void
    {
        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $this->searchCriteriaBuilder->expects(self::once())
            ->method('addFilter')
            ->with('store_id', 3)
            ->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($criteria);

        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn([$this->rule(11), $this->rule(12)]);
        $this->repository->method('getList')->with($criteria)->willReturn($results);

        $synced = [];
        $this->sync->expects(self::exactly(2))
            ->method('sync')
            ->willReturnCallback(static function (...$args) use (&$synced): void {
                $synced[] = $args;
            });

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v2');

        self::assertSame([[11, 3, 'de', 'rar_product_de_v2'], [12, 3, 'de', 'rar_product_de_v2']], $synced);
    }

    public function test_skips_non_product_entities(): void
    {
        $this->repository->expects(self::never())->method('getList');
        $this->sync->expects(self::never())->method('sync');

        $this->sut->apply('category', 3, 'de', 'rar_category_de_v2');
    }

    public function test_failing_rule_does_not_stop_remaining_rules(): void
    {
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteriaInterface::class));
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn([$this->rule(11), $this->rule(12)]);
        $this->repository->method('getList')->willReturn($results);

        $this->sync->expects(self::exactly(2))
            ->method('sync')
            ->willReturnCallback(static function (int $queryId): void {
                if ($queryId === 11) {
                    throw new \RuntimeException('typesense down');
                }
            });
        $this->logger->expects(self::once())->method('error');

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v2');
    }

    private function rule(int $id): QueryMerchandisingInterface&MockObject
    {
        $rule = $this->createMock(QueryMerchandisingInterface::class);
        $rule->method('getId')->willReturn($id);

        return $rule;
    }
}
