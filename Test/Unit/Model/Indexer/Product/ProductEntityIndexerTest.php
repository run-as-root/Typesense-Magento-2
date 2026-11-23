<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductDataBuilder;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductEntityIndexer;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProviderInterface;

final class ProductEntityIndexerTest extends TestCase
{
    private ProductDataBuilder&MockObject $dataBuilder;
    private ProductSchemaProviderInterface&MockObject $schemaProvider;
    private ProductEntityIndexer $sut;

    protected function setUp(): void
    {
        $this->dataBuilder = $this->createMock(ProductDataBuilder::class);
        $this->schemaProvider = $this->createMock(ProductSchemaProviderInterface::class);

        $this->sut = new ProductEntityIndexer($this->dataBuilder, $this->schemaProvider);
    }

    public function test_products_without_a_document_are_not_yielded(): void
    {
        $indexed = $this->createMock(Product::class);
        $skipped = $this->createMock(Product::class);
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$skipped, $indexed]));
        $this->dataBuilder->method('getProductCollection')->with([5, 6], 2)->willReturn($collection);
        $this->dataBuilder->method('build')->willReturnMap([[$skipped, 2, null], [$indexed, 2, ['id' => '6']]]);

        self::assertSame([['id' => '6']], iterator_to_array($this->sut->buildDocuments([5, 6], 2), false));
    }

    public function test_collection_settings_come_from_the_schema_provider(): void
    {
        $this->schemaProvider->method('getCollectionSettings')->willReturn(['enable_nested_fields' => true]);

        self::assertSame(['enable_nested_fields' => true], $this->sut->getCollectionSettings());
    }
}
