<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Suggestion;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Suggestion\SuggestionSchemaProvider;
use RunAsRoot\TypeSense\Model\Suggestion\AnalyticsRuleManager;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionCollectionProvisioner;

final class SuggestionCollectionProvisionerTest extends TestCase
{
    private const SCHEMA = [
        'name' => 'rar_suggestion_de',
        'fields' => [
            ['name' => 'q', 'type' => 'string'],
            ['name' => 'count', 'type' => 'int32'],
            ['name' => 'is_valid', 'type' => 'bool', 'optional' => true],
        ],
    ];

    private CollectionManagerInterface&MockObject $collectionManager;
    private AliasManagerInterface&MockObject $aliasManager;
    private AnalyticsRuleManager&MockObject $ruleManager;
    private TypeSenseConfigInterface&MockObject $config;
    private SuggestionCollectionProvisioner $sut;

    protected function setUp(): void
    {
        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entityType, string $storeCode, ?int $storeId): string => "rar_{$entityType}_{$storeCode}",
        );
        $this->collectionManager = $this->createMock(CollectionManagerInterface::class);
        $this->aliasManager = $this->createMock(AliasManagerInterface::class);
        $this->ruleManager = $this->createMock(AnalyticsRuleManager::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getSuggestionLimit')->with(3)->willReturn(1000);

        $this->sut = new SuggestionCollectionProvisioner(
            $nameResolver,
            $this->collectionManager,
            $this->aliasManager,
            new SuggestionSchemaProvider(),
            $this->ruleManager,
            $this->config,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function test_creates_missing_collection_and_points_a_popular_queries_rule_from_the_product_alias_at_it(): void
    {
        $this->collectionManager->method('collectionExists')->with('rar_suggestion_de')->willReturn(false);

        $this->collectionManager->expects(self::once())
            ->method('createCollection')
            ->with('rar_suggestion_de', self::SCHEMA);
        $this->ruleManager->expects(self::once())
            ->method('ensure')
            ->with(
                'rar_suggestion_de_popular',
                [
                    'type' => 'popular_queries',
                    'params' => [
                        'source' => ['collections' => ['rar_product_de']],
                        'destination' => ['collection' => 'rar_suggestion_de'],
                        'expand_query' => true,
                        'limit' => 1000,
                    ],
                ],
                3,
            );

        self::assertTrue($this->sut->ensure(3, 'de'));
    }

    public function test_existing_collection_keeps_its_documents(): void
    {
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->collectionManager->method('getCollection')->willReturn(self::SCHEMA + ['num_documents' => 812]);

        $this->collectionManager->expects(self::never())->method('deleteCollection');
        $this->collectionManager->expects(self::never())->method('createCollection');
        $this->ruleManager->expects(self::once())->method('ensure');

        self::assertFalse($this->sut->ensure(3, 'de'));
    }

    public function test_collection_of_the_former_search_term_export_is_replaced(): void
    {
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->collectionManager->method('getCollection')->willReturn([
            'name' => 'rar_suggestion_de',
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'query', 'type' => 'string'],
                ['name' => 'num_results', 'type' => 'int32'],
                ['name' => 'popularity', 'type' => 'int32'],
            ],
        ]);

        $this->collectionManager->expects(self::once())->method('deleteCollection')->with('rar_suggestion_de');
        $this->collectionManager->expects(self::once())->method('createCollection')->with('rar_suggestion_de', self::SCHEMA);

        self::assertTrue($this->sut->ensure(3, 'de'));
    }

    public function test_versioned_collections_behind_a_former_suggestion_alias_are_replaced(): void
    {
        $this->aliasManager->method('aliasExists')->with('rar_suggestion_de')->willReturn(true);
        $this->collectionManager->method('listCollections')->willReturn([
            ['name' => 'rar_suggestion_de_v2'],
            ['name' => 'rar_suggestion_de_v3'],
            ['name' => 'rar_suggestion_dex_v1'],
            ['name' => 'rar_product_de_v3'],
        ]);

        $this->aliasManager->expects(self::once())->method('deleteAlias')->with('rar_suggestion_de');
        $deleted = [];
        $this->collectionManager->method('deleteCollection')->willReturnCallback(
            static function (string $name) use (&$deleted): array {
                $deleted[] = $name;

                return [];
            },
        );
        $this->collectionManager->expects(self::once())->method('createCollection')->with('rar_suggestion_de', self::SCHEMA);

        self::assertTrue($this->sut->ensure(3, 'de'));
        self::assertSame(['rar_suggestion_de_v2', 'rar_suggestion_de_v3'], $deleted);
    }

    public function test_rule_limit_comes_from_the_store_configuration(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getSuggestionLimit')->willReturn(250);
        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entityType, string $storeCode): string => "rar_{$entityType}_{$storeCode}",
        );
        $this->collectionManager->method('collectionExists')->willReturn(true);
        $this->collectionManager->method('getCollection')->willReturn(self::SCHEMA);
        $sut = new SuggestionCollectionProvisioner(
            $nameResolver,
            $this->collectionManager,
            $this->aliasManager,
            new SuggestionSchemaProvider(),
            $this->ruleManager,
            $this->config,
            $this->createMock(LoggerInterface::class),
        );

        $this->ruleManager->expects(self::once())
            ->method('ensure')
            ->with('rar_suggestion_at_popular', self::callback(
                static fn (array $rule): bool => $rule['params']['limit'] === 250,
            ), 5);

        $sut->ensure(5, 'at');
    }
}
