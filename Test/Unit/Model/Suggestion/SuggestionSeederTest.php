<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Suggestion;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Suggestion\AnalyticsQueryKey;
use RunAsRoot\TypeSense\Model\Suggestion\SearchQueryReader;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionSeeder;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionTermFilter;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;

final class SuggestionSeederTest extends TestCase
{
    private SearchQueryReader&MockObject $reader;
    private TypeSenseConfigInterface&MockObject $config;
    private Documents&MockObject $documents;
    private LoggerInterface&MockObject $logger;
    private SuggestionSeeder $sut;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(SearchQueryReader::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getSuggestionLimit')->willReturn(1000);
        $this->config->method('getBatchSize')->willReturn(200);
        $this->config->method('getSuggestionMaxLength')->willReturn(60);
        $this->config->method('getSuggestionDenylist')->willReturn(['casino']);

        $this->documents = $this->createMock(Documents::class);
        $collection = $this->createMock(Collection::class);
        $collection->documents = $this->documents;
        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('rar_suggestion_de')->willReturn($collection);
        $client = $this->createMock(Client::class);
        $client->collections = $collections;
        $clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $clientFactory->method('create')->willReturn($client);

        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->with('suggestion', 'de', 3)->willReturn('rar_suggestion_de');

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new SuggestionSeeder(
            $this->reader,
            new AnalyticsQueryKey(),
            new SuggestionTermFilter($this->config),
            $nameResolver,
            $this->config,
            $clientFactory,
            $this->logger,
        );
    }

    public function test_creates_documents_under_the_ids_typesense_analytics_uses(): void
    {
        $this->reader->expects(self::once())->method('getTopQueries')->with(3, 1000)->willReturn([
            ['query' => 'Birne', 'popularity' => 42],
            ['query' => 'gin', 'popularity' => 7],
        ]);

        $this->documents->expects(self::once())
            ->method('import')
            ->with(
                [
                    ['id' => '10629654979707214948', 'q' => 'birne', 'count' => 42],
                    ['id' => '393136922600598429', 'q' => 'gin', 'count' => 7],
                ],
                ['action' => 'create'],
            )
            ->willReturn([['success' => true], ['success' => true]]);

        self::assertSame(
            ['total' => 2, 'imported' => 2, 'existing' => 0, 'failed' => 0],
            $this->sut->seed(3, 'de'),
        );
    }

    public function test_terms_normalizing_to_the_same_query_are_merged_and_terms_never_suggested_skipped(): void
    {
        $this->reader->method('getTopQueries')->willReturn([
            ['query' => 'Birne', 'popularity' => 42],
            ['query' => ' birne ', 'popularity' => 8],
            ['query' => '   ', 'popularity' => 5],
            ['query' => '*', 'popularity' => 3],
            ['query' => "\xC3\x28", 'popularity' => 3],
            ['query' => 'Gin', 'popularity' => 2],
            ['query' => 'casino bonus', 'popularity' => 90],
            ['query' => '4711', 'popularity' => 9],
            ['query' => str_repeat('x', 61), 'popularity' => 9],
        ]);

        $imported = [];
        $this->documents->method('import')->willReturnCallback(
            static function (array $documents) use (&$imported): array {
                $imported = $documents;

                return array_fill(0, count($documents), ['success' => true]);
            },
        );

        $this->sut->seed(3, 'de');

        self::assertSame(['birne', 'gin'], array_column($imported, 'q'));
        self::assertSame([50, 2], array_column($imported, 'count'));
    }

    public function test_existing_documents_are_kept_and_counted_separately_from_failures(): void
    {
        $this->reader->method('getTopQueries')->willReturn([
            ['query' => 'birne', 'popularity' => 42],
            ['query' => 'gin', 'popularity' => 7],
            ['query' => 'rum', 'popularity' => 3],
        ]);
        $this->documents->method('import')->willReturn([
            ['success' => true],
            ['success' => false, 'code' => 409, 'error' => 'A document with id 393136922600598429 already exists.'],
            ['success' => false, 'code' => 400, 'error' => 'Bad JSON.'],
        ]);

        $this->logger->expects(self::once())->method('warning');

        self::assertSame(
            ['total' => 3, 'imported' => 1, 'existing' => 1, 'failed' => 1],
            $this->sut->seed(3, 'de'),
        );
    }

    public function test_a_requested_limit_above_the_rule_limit_is_capped(): void
    {
        $limits = [];
        $this->reader->method('getTopQueries')->willReturnCallback(
            static function (int $storeId, int $limit) use (&$limits): array {
                $limits[] = $limit;

                return [];
            },
        );

        $this->sut->seed(3, 'de', 5000);
        $this->sut->seed(3, 'de', 25);

        self::assertSame([1000, 25], $limits);
    }

    public function test_popularity_beyond_int32_is_capped(): void
    {
        $this->reader->method('getTopQueries')->willReturn([['query' => 'birne', 'popularity' => 3000000000]]);

        $this->documents->expects(self::once())
            ->method('import')
            ->with([['id' => '10629654979707214948', 'q' => 'birne', 'count' => 2147483647]], self::anything())
            ->willReturn([['success' => true]]);

        $this->sut->seed(3, 'de');
    }

    public function test_nothing_to_seed_sends_no_request(): void
    {
        $this->reader->method('getTopQueries')->willReturn([]);

        $this->documents->expects(self::never())->method('import');

        self::assertSame(['total' => 0, 'imported' => 0, 'existing' => 0, 'failed' => 0], $this->sut->seed(3, 'de'));
    }

    public function test_imports_in_batches_of_the_configured_size(): void
    {
        $rows = [];
        for ($i = 0; $i < 450; $i++) {
            $rows[] = ['query' => 'term ' . $i, 'popularity' => 1000 - $i];
        }
        $this->reader->method('getTopQueries')->willReturn($rows);

        $sizes = [];
        $this->documents->expects(self::exactly(3))->method('import')->willReturnCallback(
            static function (array $documents) use (&$sizes): array {
                $sizes[] = count($documents);

                return array_fill(0, count($documents), ['success' => true]);
            },
        );

        $stats = $this->sut->seed(3, 'de');

        self::assertSame([200, 200, 50], $sizes);
        self::assertSame(450, $stats['imported']);
    }
}
