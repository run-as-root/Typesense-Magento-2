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

        self::assertSame(['name' => 10, 'material' => 3], $this->sut->getWeightedFields());
    }

    public function test_weighted_fields_fall_back_to_name_when_nothing_valid(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);
        $this->config->method('getSearchableAttributes')->willReturn([['attribute' => 'color', 'weight' => 5]]);

        self::assertSame(['name' => 1], $this->sut->getWeightedFields());
    }

    public function test_weighted_fields_keep_first_row_of_duplicate_attribute(): void
    {
        $this->config->method('getAdditionalAttributes')->willReturn([]);
        $this->config->method('getSearchableAttributes')->willReturn([
            ['attribute' => 'sku', 'weight' => 9],
            ['attribute' => 'name', 'weight' => 5],
            ['attribute' => 'sku', 'weight' => 2],
        ]);

        self::assertSame(['sku' => 9, 'name' => 5], $this->sut->getWeightedFields());
    }
}
