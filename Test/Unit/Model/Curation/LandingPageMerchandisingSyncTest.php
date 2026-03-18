<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Curation;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\LandingPageInterface;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;
use RunAsRoot\TypeSense\Model\Curation\LandingPageMerchandisingSync;

final class LandingPageMerchandisingSyncTest extends TestCase
{
    private OverrideManagerInterface&MockObject $overrideManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private LandingPageRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private LandingPageMerchandisingSync $sut;

    protected function setUp(): void
    {
        $this->overrideManager = $this->createMock(OverrideManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->repository = $this->createMock(LandingPageRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new LandingPageMerchandisingSync(
            $this->overrideManager,
            $this->collectionNameResolver,
            $this->repository,
            $this->logger,
        );
    }

    public function test_sync_active_page_with_filter_calls_create_override(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('isActive')->willReturn(true);
        $landingPage->method('getQuery')->willReturn('summer sale');
        $landingPage->method('getFilterBy')->willReturn('brand:=Nike');
        $landingPage->method('getIncludes')->willReturn([['id' => '10', 'position' => 1]]);
        $landingPage->method('getExcludes')->willReturn([['id' => '20']]);

        $this->repository->method('getById')->with(3)->willReturn($landingPage);
        $this->collectionNameResolver->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('rar_products_default');

        $expectedPayload = [
            'rule' => [
                'query' => 'summer sale',
                'match' => 'exact',
                'filter_by' => 'brand:=Nike',
            ],
            'includes' => [['id' => '10', 'position' => 1]],
            'excludes' => [['id' => '20']],
        ];

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with('rar_products_default', 'landing_3', $expectedPayload);

        $this->sut->sync(3, 1, 'default');
    }

    public function test_sync_active_page_without_filter_omits_filter_by_key(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('isActive')->willReturn(true);
        $landingPage->method('getQuery')->willReturn('*');
        $landingPage->method('getFilterBy')->willReturn(null);
        $landingPage->method('getIncludes')->willReturn([]);
        $landingPage->method('getExcludes')->willReturn([]);

        $this->repository->method('getById')->with(8)->willReturn($landingPage);
        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_default');

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with(
                'rar_products_default',
                'landing_8',
                self::callback(function (array $payload): bool {
                    return !array_key_exists('filter_by', $payload['rule'])
                        && $payload['rule']['query'] === '*'
                        && $payload['rule']['match'] === 'exact';
                }),
            );

        $this->sut->sync(8, 1, 'default');
    }

    public function test_sync_inactive_page_calls_delete_override(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('isActive')->willReturn(false);

        $this->repository->method('getById')->with(5)->willReturn($landingPage);
        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_german');

        $this->overrideManager->expects(self::never())->method('createOverride');
        $this->overrideManager->expects(self::once())
            ->method('deleteOverride')
            ->with('rar_products_german', 'landing_5');

        $this->sut->sync(5, 2, 'german');
    }

    public function test_sync_nonexistent_page_catches_exception_and_deletes_override(): void
    {
        $this->repository->method('getById')->with(77)
            ->willThrowException(new NoSuchEntityException());

        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_default');

        $this->overrideManager->expects(self::never())->method('createOverride');
        $this->overrideManager->expects(self::once())
            ->method('deleteOverride')
            ->with('rar_products_default', 'landing_77');

        $this->sut->sync(77, 1, 'default');
    }

    public function test_override_id_format_is_correct(): void
    {
        $landingPage = $this->createMock(LandingPageInterface::class);
        $landingPage->method('isActive')->willReturn(true);
        $landingPage->method('getQuery')->willReturn('test');
        $landingPage->method('getFilterBy')->willReturn(null);
        $landingPage->method('getIncludes')->willReturn([]);
        $landingPage->method('getExcludes')->willReturn([]);

        $this->repository->method('getById')->with(99)->willReturn($landingPage);
        $this->collectionNameResolver->method('resolve')->willReturn('rar_products_fr');

        $this->overrideManager->expects(self::once())
            ->method('createOverride')
            ->with('rar_products_fr', 'landing_99', self::anything());

        $this->sut->sync(99, 4, 'fr');
    }
}
