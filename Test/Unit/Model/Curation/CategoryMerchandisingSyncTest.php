<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Curation;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryMerchandisingRepositoryInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;

final class CategoryMerchandisingSyncTest extends TestCase
{
    private OverrideManagerInterface&MockObject $overrideManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private CategoryMerchandisingRepositoryInterface&MockObject $repository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private LoggerInterface&MockObject $logger;
    private CategoryMerchandisingSync $sut;

    protected function setUp(): void
    {
        $this->overrideManager = $this->createMock(OverrideManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->repository = $this->createMock(CategoryMerchandisingRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new CategoryMerchandisingSync(
            $this->overrideManager,
            $this->collectionNameResolver,
            $this->repository,
            $this->searchCriteriaBuilder,
            $this->logger,
        );
    }

    public function test_sync_with_pin_and_hide_rules_calls_create_override_with_correct_payload(): void
    {
        $categoryId = 5;
        $storeId = 1;
        $storeCode = 'default';
        $collectionName = 'rar_products_default';

        $pinRule = $this->createMock(CategoryMerchandisingInterface::class);
        $pinRule->method('getAction')->willReturn('pin');
        $pinRule->method('getProductId')->willReturn(101);
        $pinRule->method('getPosition')->willReturn(1);

        $hideRule = $this->createMock(CategoryMerchandisingInterface::class);
        $hideRule->method('getAction')->willReturn('hide');
        $hideRule->method('getProductId')->willReturn(202);
        $hideRule->method('getPosition')->willReturn(0);

        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$pinRule, $hideRule]);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->repository->method('getList')->with($searchCriteria)->willReturn($searchResults);

        $this->collectionNameResolver->method('resolve')
            ->with('product', $storeCode, $storeId)
            ->willReturn($collectionName);

        $expectedPayload = [
            'rule' => [
                'query' => '*',
                'match' => 'exact',
                'filter_by' => "category_ids:={$categoryId}",
            ],
            'includes' => [
                ['id' => '101', 'position' => 1],
            ],
            'excludes' => [
                ['id' => '202'],
            ],
        ];

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with($collectionName, 'cat_merch_5_1', $expectedPayload);

        $this->sut->sync($categoryId, $storeId, $storeCode);
    }

    public function test_sync_with_no_rules_calls_delete_override(): void
    {
        $categoryId = 3;
        $storeId = 2;
        $storeCode = 'german';
        $collectionName = 'rar_products_german';

        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([]);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->repository->method('getList')->with($searchCriteria)->willReturn($searchResults);

        $this->collectionNameResolver->method('resolve')
            ->with('product', $storeCode, $storeId)
            ->willReturn($collectionName);

        $this->overrideManager->expects(self::never())->method('createOverride');
        $this->overrideManager->expects(self::once())
            ->method('deleteOverride')
            ->with($collectionName, 'cat_merch_3_2');

        $this->sut->sync($categoryId, $storeId, $storeCode);
    }

    public function test_override_id_format_is_correct(): void
    {
        $categoryId = 42;
        $storeId = 7;
        $storeCode = 'en_us';
        $collectionName = 'rar_products_en_us';

        $pinRule = $this->createMock(CategoryMerchandisingInterface::class);
        $pinRule->method('getAction')->willReturn('pin');
        $pinRule->method('getProductId')->willReturn(10);
        $pinRule->method('getPosition')->willReturn(2);

        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$pinRule]);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->repository->method('getList')->willReturn($searchResults);
        $this->collectionNameResolver->method('resolve')->willReturn($collectionName);

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with($collectionName, 'cat_merch_42_7', self::anything());

        $this->sut->sync($categoryId, $storeId, $storeCode);
    }

    public function test_collection_name_is_resolved_via_collection_name_resolver(): void
    {
        $categoryId = 1;
        $storeId = 3;
        $storeCode = 'french';
        $resolvedCollection = 'rar_products_french';

        $pinRule = $this->createMock(CategoryMerchandisingInterface::class);
        $pinRule->method('getAction')->willReturn('pin');
        $pinRule->method('getProductId')->willReturn(55);
        $pinRule->method('getPosition')->willReturn(1);

        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$pinRule]);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->repository->method('getList')->willReturn($searchResults);

        $this->collectionNameResolver->expects(self::once())
            ->method('resolve')
            ->with('product', $storeCode, $storeId)
            ->willReturn($resolvedCollection);

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with($resolvedCollection, self::anything(), self::anything());

        $this->sut->sync($categoryId, $storeId, $storeCode);
    }

    public function test_sync_with_only_pin_rules_produces_empty_excludes(): void
    {
        $categoryId = 10;
        $storeId = 1;
        $storeCode = 'default';
        $collectionName = 'rar_products_default';

        $pinRule1 = $this->createMock(CategoryMerchandisingInterface::class);
        $pinRule1->method('getAction')->willReturn('pin');
        $pinRule1->method('getProductId')->willReturn(11);
        $pinRule1->method('getPosition')->willReturn(1);

        $pinRule2 = $this->createMock(CategoryMerchandisingInterface::class);
        $pinRule2->method('getAction')->willReturn('pin');
        $pinRule2->method('getProductId')->willReturn(22);
        $pinRule2->method('getPosition')->willReturn(2);

        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$pinRule1, $pinRule2]);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->repository->method('getList')->willReturn($searchResults);
        $this->collectionNameResolver->method('resolve')->willReturn($collectionName);

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with(
                $collectionName,
                'cat_merch_10_1',
                self::callback(function (array $payload): bool {
                    return $payload['includes'] === [
                        ['id' => '11', 'position' => 1],
                        ['id' => '22', 'position' => 2],
                    ] && $payload['excludes'] === [];
                }),
            );

        $this->sut->sync($categoryId, $storeId, $storeCode);
    }
}
