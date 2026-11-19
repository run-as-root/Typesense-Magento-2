<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product\Decompound;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\CatalogWordsProvider;

final class CatalogWordsProviderTest extends TestCase
{
    private const int STORE_ID = 17;
    private const int WEBSITE_ID = 8;

    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private CatalogWordsProvider $sut;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('join')->willReturnSelf();
        $this->select->method('joinLeft')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->select->method('columns')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', (string) $value, $text),
        );
        $this->connection->method('getIfNullSql')->willReturn(new \Zend_Db_Expr('IFNULL(s.value, d.value)'));

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getId')->willReturn('73');
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->with('catalog_product', 'name')->willReturn($attribute);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('row_id');
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->with(ProductInterface::class)->willReturn($metadata);

        $this->sut = new CatalogWordsProvider($resourceConnection, $eavConfig, $storeManager, $metadataPool);
    }

    public function test_returns_lower_case_letter_words_of_product_names(): void
    {
        $this->connection->method('fetchCol')->willReturn([
            'Tisch Bistro 2-tlg.',
            'Esstisch Swansea 120x80cm',
            'Gartenstuhl, Größe XL',
            null,
        ]);

        self::assertSame(
            ['tisch' => true, 'bistro' => true, 'tlg' => true, 'esstisch' => true, 'swansea' => true, 'x' => true,
                'cm' => true, 'gartenstuhl' => true, 'größe' => true, 'xl' => true],
            $this->sut->getWords(self::STORE_ID),
        );
    }

    public function test_only_products_of_the_store_website_are_read(): void
    {
        $this->select->expects(self::once())->method('where')->with('pw.website_id = ?', self::WEBSITE_ID);
        $this->connection->method('fetchCol')->willReturn([]);

        $this->sut->getWords(self::STORE_ID);
    }

    public function test_store_value_falls_back_to_the_default_value_on_the_link_field(): void
    {
        $joins = [];
        $this->select->method('joinLeft')->willReturnCallback(
            function (array $name, string $condition) use (&$joins): Select {
                $joins[key($name)] = $condition;

                return $this->select;
            },
        );
        $this->connection->expects(self::once())->method('getIfNullSql')->with('s.value', 'd.value')
            ->willReturn(new \Zend_Db_Expr('IFNULL(s.value, d.value)'));
        $this->connection->method('fetchCol')->willReturn([]);

        $this->sut->getWords(self::STORE_ID);

        self::assertStringContainsString('d.row_id = e.row_id', $joins['d']);
        self::assertStringContainsString('d.store_id = 0', $joins['d']);
        self::assertStringContainsString('s.store_id = ' . self::STORE_ID, $joins['s']);
    }

    public function test_names_are_loaded_once_per_store(): void
    {
        $this->connection->expects(self::exactly(2))->method('fetchCol')->willReturn(['Tisch']);

        $this->sut->getWords(self::STORE_ID);
        $this->sut->getWords(self::STORE_ID);
        $this->sut->getWords(25);
    }
}
