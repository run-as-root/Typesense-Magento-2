<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Suggestion;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\SelfMaintainedEntityInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Suggestion\SuggestionEntityIndexer;
use RunAsRoot\TypeSense\Model\Indexer\Suggestion\SuggestionSchemaProvider;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionCollectionProvisioner;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionSeeder;

final class SuggestionEntityIndexerTest extends TestCase
{
    private SuggestionCollectionProvisioner&MockObject $provisioner;
    private SuggestionSeeder&MockObject $seeder;
    private TypeSenseConfigInterface&MockObject $config;
    private SuggestionEntityIndexer $sut;

    protected function setUp(): void
    {
        $this->provisioner = $this->createMock(SuggestionCollectionProvisioner::class);
        $this->seeder = $this->createMock(SuggestionSeeder::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);

        $this->sut = new SuggestionEntityIndexer(
            new SuggestionSchemaProvider(),
            $this->provisioner,
            $this->seeder,
            $this->config,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function test_is_a_self_maintained_entity_with_the_analytics_schema(): void
    {
        self::assertInstanceOf(SelfMaintainedEntityInterface::class, $this->sut);
        self::assertSame('suggestion', $this->sut->getEntityType());
        self::assertSame('typesense_suggestion', $this->sut->getIndexerCode());
        self::assertSame(
            [['name' => 'q', 'type' => 'string'], ['name' => 'count', 'type' => 'int32']],
            $this->sut->getSchemaFields(),
        );
        self::assertSame([], iterator_to_array($this->sut->buildDocuments([], 3)));
    }

    public function test_a_newly_created_collection_is_seeded_from_the_magento_search_terms(): void
    {
        $this->config->method('isSuggestionIndexingEnabled')->with(3)->willReturn(true);
        $this->provisioner->expects(self::once())->method('ensure')->with(3, 'de')->willReturn(true);

        $this->seeder->expects(self::once())
            ->method('seed')
            ->with(3, 'de')
            ->willReturn(['total' => 2, 'imported' => 2, 'existing' => 0, 'failed' => 0]);

        $this->sut->provision(3, 'de');
    }

    public function test_an_existing_collection_is_not_seeded_again(): void
    {
        $this->config->method('isSuggestionIndexingEnabled')->willReturn(true);
        $this->provisioner->method('ensure')->willReturn(false);

        $this->seeder->expects(self::never())->method('seed');

        $this->sut->provision(3, 'de');
    }

    public function test_stores_with_suggestions_disabled_are_skipped(): void
    {
        $this->config->method('isSuggestionIndexingEnabled')->willReturn(false);

        $this->provisioner->expects(self::never())->method('ensure');
        $this->seeder->expects(self::never())->method('seed');

        $this->sut->provision(3, 'de');
    }
}
