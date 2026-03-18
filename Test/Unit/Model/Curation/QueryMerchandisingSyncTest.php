<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Curation;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\QueryMerchandisingInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;
use RunAsRoot\TypeSense\Api\QueryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Model\Curation\QueryMerchandisingSync;

final class QueryMerchandisingSyncTest extends TestCase
{
    private OverrideManagerInterface&MockObject $overrideManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private QueryMerchandisingRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private QueryMerchandisingSync $sut;

    protected function setUp(): void
    {
        $this->overrideManager = $this->createMock(OverrideManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->repository = $this->createMock(QueryMerchandisingRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new QueryMerchandisingSync(
            $this->overrideManager,
            $this->collectionNameResolver,
            $this->repository,
            $this->logger,
        );
    }

    public function test_sync_active_rule_calls_create_override_with_correct_payload(): void
    {
        $queryRule = $this->createMock(QueryMerchandisingInterface::class);
        $queryRule->method('isActive')->willReturn(true);
        $queryRule->method('getQuery')->willReturn('red shoes');
        $queryRule->method('getMatchType')->willReturn('exact');
        $queryRule->method('getIncludes')->willReturn([['id' => '101', 'position' => 1]]);
        $queryRule->method('getExcludes')->willReturn([['id' => '202']]);

        $this->repository->method('getById')->with(5)->willReturn($queryRule);
        $this->collectionNameResolver->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('rar_products_default');

        $expectedPayload = [
            'rule' => [
                'query' => 'red shoes',
                'match' => 'exact',
            ],
            'includes' => [['id' => '101', 'position' => 1]],
            'excludes' => [['id' => '202']],
        ];

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with('rar_products_default', 'query_merch_5', $expectedPayload);

        $this->sut->sync(5, 1, 'default');
    }

    public function test_sync_inactive_rule_calls_delete_override(): void
    {
        $queryRule = $this->createMock(QueryMerchandisingInterface::class);
        $queryRule->method('isActive')->willReturn(false);

        $this->repository->method('getById')->with(7)->willReturn($queryRule);
        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_german');

        $this->overrideManager->expects(self::never())->method('createOverride');
        $this->overrideManager->expects(self::once())
            ->method('deleteOverride')
            ->with('rar_products_german', 'query_merch_7');

        $this->sut->sync(7, 2, 'german');
    }

    public function test_sync_nonexistent_rule_catches_exception_and_deletes_override(): void
    {
        $this->repository->method('getById')->with(99)
            ->willThrowException(new NoSuchEntityException());

        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_default');

        $this->overrideManager->expects(self::never())->method('createOverride');
        $this->overrideManager->expects(self::once())
            ->method('deleteOverride')
            ->with('rar_products_default', 'query_merch_99');

        $this->sut->sync(99, 1, 'default');
    }

    public function test_override_id_format_is_correct(): void
    {
        $queryRule = $this->createMock(QueryMerchandisingInterface::class);
        $queryRule->method('isActive')->willReturn(true);
        $queryRule->method('getQuery')->willReturn('test');
        $queryRule->method('getMatchType')->willReturn('contains');
        $queryRule->method('getIncludes')->willReturn([]);
        $queryRule->method('getExcludes')->willReturn([]);

        $this->repository->method('getById')->with(42)->willReturn($queryRule);
        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_en_us');

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with('rar_products_en_us', 'query_merch_42', self::anything());

        $this->sut->sync(42, 3, 'en_us');
    }
}
