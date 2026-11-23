<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Listing;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\ProductSchemaProviderInterface;
use RunAsRoot\TypeSense\Model\Listing\FacetConfigBuilder;

final class FacetConfigBuilderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private ProductSchemaProviderInterface&MockObject $schemaProvider;
    private EavConfig&MockObject $eavConfig;
    private FacetConfigBuilder $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->schemaProvider = $this->createMock(ProductSchemaProviderInterface::class);
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->sut = new FacetConfigBuilder($this->config, $this->schemaProvider, $this->eavConfig);
    }

    public function test_unsupported_facet_types_fall_back_to_dropdown_price_is_always_a_slider_and_labels_use_store_label_or_code(): void
    {
        $this->config->method('getFacetFilters')->willReturn([
            'color', 'size', 'volume', 'weight', 'in_stock', 'price', 'visibility', 'category_ids', 'sales_count',
        ]);
        $this->config->method('getFacetTypes')->with(1)->willReturn([
            'color' => ['type' => 'slider', 'searchable' => true],
            'size' => ['type' => 'checkbox', 'searchable' => true],
            'volume' => ['type' => 'slider', 'searchable' => false],
            'in_stock' => ['type' => 'checkbox', 'searchable' => true],
            'price' => ['type' => 'dropdown', 'searchable' => true],
        ]);
        $this->schemaProvider->method('getFields')->willReturn([
            ['name' => 'in_stock', 'type' => 'bool', 'facet' => true],
            ['name' => 'color', 'type' => 'string', 'facet' => true],
            ['name' => 'size', 'type' => 'string[]', 'facet' => true],
            ['name' => 'volume', 'type' => 'float', 'facet' => true],
            ['name' => 'price', 'type' => 'float', 'facet' => true],
            ['name' => 'visibility', 'type' => 'int32', 'facet' => true],
            ['name' => 'category_ids', 'type' => 'int32[]'],
            ['name' => 'sales_count', 'type' => 'int64', 'facet' => true],
        ]);
        $color = $this->createMock(Attribute::class);
        $color->method('getId')->willReturn(93);
        $color->method('getStoreLabel')->with(1)->willReturn('Farbe');
        $missing = $this->createMock(Attribute::class);
        $missing->method('getId')->willReturn(null);
        $this->eavConfig->method('getAttribute')->willReturnCallback(
            static fn (string $entityType, string $code): Attribute => $code === 'color' ? $color : $missing,
        );

        self::assertSame([
            // The listing state holds one range (price), a checkbox needs a bool field; value search only exists on
            // dropdowns. A numeric field carries its Typesense type, so its values are filtered unquoted.
            ['field' => 'color', 'label' => 'Farbe', 'type' => 'dropdown', 'searchable' => true, 'boolean' => false, 'numeric' => false],
            ['field' => 'size', 'label' => 'Size', 'type' => 'dropdown', 'searchable' => true, 'boolean' => false, 'numeric' => false],
            ['field' => 'volume', 'label' => 'Volume', 'type' => 'dropdown', 'searchable' => false, 'boolean' => false, 'numeric' => 'float'],
            ['field' => 'weight', 'label' => 'Weight', 'type' => 'dropdown', 'searchable' => false, 'boolean' => false, 'numeric' => false],
            ['field' => 'in_stock', 'label' => 'Availability', 'type' => 'checkbox', 'searchable' => false, 'boolean' => true, 'numeric' => false],
            ['field' => 'price', 'label' => 'Price', 'type' => 'slider', 'searchable' => false, 'boolean' => false, 'numeric' => 'float'],
            ['field' => 'visibility', 'label' => 'Visibility', 'type' => 'dropdown', 'searchable' => false, 'boolean' => false, 'numeric' => 'int32'],
            ['field' => 'category_ids', 'label' => 'Category Ids', 'type' => 'dropdown', 'searchable' => false, 'boolean' => false, 'numeric' => 'int32'],
            ['field' => 'sales_count', 'label' => 'Sales Count', 'type' => 'dropdown', 'searchable' => false, 'boolean' => false, 'numeric' => 'int64'],
        ], $this->sut->build(1));
    }
}
