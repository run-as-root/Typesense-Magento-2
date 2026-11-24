<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Search;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Search\SearchableAttributesProvider;

final class SearchableAttributesProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private ProductAttributeRepositoryInterface&MockObject $attributeRepository;
    private SearchableAttributesProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->attributeRepository = $this->createMock(ProductAttributeRepositoryInterface::class);
        $this->sut = new SearchableAttributesProvider($this->config, $this->attributeRepository);
    }

    public function test_options_contain_core_fields_and_additional_attributes_with_labels(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn(['material', 'ghost']);
        $material = $this->createMock(ProductAttributeInterface::class);
        $material->method('getDefaultFrontendLabel')->willReturn('Material');
        $this->attributeRepository->method('get')->willReturnCallback(
            static fn (string $code) => $code === 'material' ? $material : throw new NoSuchEntityException(),
        );

        $options = $this->sut->getOptions();

        self::assertSame('Name', $options['name']);
        self::assertSame('Name (decompounded)', $options['name_decompounded']);
        self::assertSame('Material', $options['material']);
        self::assertSame('ghost', $options['ghost']);
    }

    public function test_weighted_fields_drop_attributes_that_are_not_indexed(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn(['material']);
        $this->config->method('getSearchableAttributes')->willReturn([
            ['attribute' => 'name', 'weight' => 10],
            ['attribute' => 'color', 'weight' => 8],
            ['attribute' => 'name_decompounded', 'weight' => 7],
            ['attribute' => 'material', 'weight' => 3],
        ]);

        self::assertSame(['name' => 10, 'children_text' => 10, 'material' => 3], $this->sut->getWeightedFields());
    }

    public function test_weighted_fields_keep_name_decompounded_when_decompounding_is_enabled_for_the_store(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);
        $this->config->method('isGermanDecompoundingEnabled')->with(5)->willReturn(true);
        $this->config->method('getSearchableAttributes')->willReturn([
            ['attribute' => 'name', 'weight' => 10],
            ['attribute' => 'name_decompounded', 'weight' => 7],
        ]);

        self::assertSame(['name' => 10, 'children_text' => 10, 'name_decompounded' => 7], $this->sut->getWeightedFields(5));
    }

    public function test_weighted_fields_fall_back_to_name_when_nothing_valid(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);
        $this->config->method('getSearchableAttributes')->willReturn([['attribute' => 'color', 'weight' => 5]]);

        self::assertSame(['name' => 1, 'children_text' => 1], $this->sut->getWeightedFields());
    }

    public function test_weighted_fields_keep_first_row_of_duplicate_attribute(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);
        $this->config->method('getSearchableAttributes')->willReturn([
            ['attribute' => 'sku', 'weight' => 9],
            ['attribute' => 'name', 'weight' => 5],
            ['attribute' => 'sku', 'weight' => 2],
        ]);

        self::assertSame(['sku' => 9, 'name' => 5, 'children_text' => 5], $this->sut->getWeightedFields());
    }

    public function test_children_text_keeps_its_configured_weight(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);
        $this->config->method('getSearchableAttributes')->willReturn([
            ['attribute' => 'name', 'weight' => 10],
            ['attribute' => 'sku', 'weight' => 9],
            ['attribute' => 'children_text', 'weight' => 2],
        ]);

        self::assertSame(['name' => 10, 'sku' => 9, 'children_text' => 2], $this->sut->getWeightedFields());
    }

    public function test_children_text_gets_the_lowest_weight_when_name_is_not_searched(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);
        $this->config->method('getSearchableAttributes')->willReturn([
            ['attribute' => 'sku', 'weight' => 9],
            ['attribute' => 'description', 'weight' => 3],
        ]);

        self::assertSame(['sku' => 9, 'description' => 3, 'children_text' => 3], $this->sut->getWeightedFields());
    }

    public function test_children_text_is_offered_as_a_searchable_attribute(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        self::assertSame('Child Products', $this->sut->getOptions()['children_text']);
    }
}
