<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Collection;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Collection\EntityCollectionPurger;

final class EntityCollectionPurgerTest extends TestCase
{
    private CollectionManagerInterface&MockObject $collectionManager;
    private AliasManagerInterface&MockObject $aliasManager;
    private EntityCollectionPurger $sut;

    protected function setUp(): void
    {
        $storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $storeRepository->method('getList')->willReturn([
            $this->createStore(0, 'admin'),
            $this->createStore(1, 'de'),
            $this->createStore(2, 'at'),
        ]);
        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entityType, string $storeCode): string => "prinz_{$entityType}_{$storeCode}",
        );
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'prinz_customer_de_v2'],
            ['name' => 'prinz_customer_de_v3'],
            ['name' => 'prinz_customer_at'],
            ['name' => 'prinz_customer_default_v1'],
            ['name' => 'prinz_customer_de_v3_backup'],
            ['name' => 'prinz_product_de_v4'],
        ]);
        $this->aliasManager = $this->createMock(AliasManagerInterface::class);
        $this->aliasManager->method('aliasExists')->willReturnCallback(
            static fn (string $alias): bool => $alias === 'prinz_customer_de',
        );

        $this->sut = new EntityCollectionPurger(
            $storeRepository,
            $nameResolver,
            $this->collectionManager,
            $this->aliasManager,
        );
    }

    public function test_deletes_the_aliases_and_every_version_of_the_entity_type_in_every_store(): void
    {
        $this->aliasManager->expects(self::once())->method('deleteAlias')->with('prinz_customer_de');
        $deleted = [];
        $this->collectionManager->method('deleteCollection')->willReturnCallback(
            static function (string $name) use (&$deleted): array {
                $deleted[] = $name;

                return [];
            },
        );

        $result = $this->sut->purge('customer');

        self::assertSame(['prinz_customer_de_v2', 'prinz_customer_de_v3', 'prinz_customer_at'], $deleted);
        self::assertSame(
            ['prinz_customer_de (alias)', 'prinz_customer_de_v2', 'prinz_customer_de_v3', 'prinz_customer_at'],
            $result,
        );
    }

    private function createStore(int $id, string $code): StoreInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);

        return $store;
    }
}
