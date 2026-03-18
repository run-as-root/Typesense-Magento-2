<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Collection;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Collection\CollectionNameResolver;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

final class CollectionNameResolverTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private CollectionNameResolver $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new CollectionNameResolver($this->config);
    }

    public function test_resolve_returns_prefixed_entity_store_name(): void
    {
        $this->config->method('getCollectionName')
            ->with('products', 'default', null)
            ->willReturn('rar_products_default');

        self::assertSame('rar_products_default', $this->sut->resolve('products', 'default'));
    }

    public function test_resolve_versioned_appends_version_number(): void
    {
        $this->config->method('getCollectionName')
            ->with('products', 'default', null)
            ->willReturn('rar_products_default');

        self::assertSame('rar_products_default_v3', $this->sut->resolveVersioned('products', 'default', 3));
    }

    public function test_resolve_passes_store_id_to_config(): void
    {
        $this->config->expects(self::once())
            ->method('getCollectionName')
            ->with('categories', 'german', 5)
            ->willReturn('rar_categories_german');

        self::assertSame('rar_categories_german', $this->sut->resolve('categories', 'german', 5));
    }
}
