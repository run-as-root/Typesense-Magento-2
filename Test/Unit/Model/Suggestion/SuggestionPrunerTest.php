<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Suggestion;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Search\SearchRequestParamsBuilder;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionPruner;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionTermFilter;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;
use Typesense\MultiSearch;

final class SuggestionPrunerTest extends TestCase
{
    private Documents&MockObject $documents;
    private MultiSearch&MockObject $multiSearch;
    private TypeSenseConfigInterface&MockObject $config;
    /** @var array<int, array<string, mixed>> */
    private array $stored = [];
    /** @var string[] */
    private array $productHits = [];
    /** @var array<int, array<string, mixed>> */
    private array $productSearches = [];
    private int $limit = 1000;
    private bool $checksFail = false;
    private SuggestionPruner $sut;

    protected function setUp(): void
    {
        $this->documents = $this->createMock(Documents::class);
        $this->documents->method('search')->willReturnCallback(function (array $params): array {
            $offset = ($params['page'] - 1) * $params['per_page'];

            return ['hits' => array_map(
                static fn (array $document): array => ['document' => $document],
                array_slice($this->stored, $offset, $params['per_page']),
            )];
        });
        $collection = $this->createMock(Collection::class);
        $collection->documents = $this->documents;
        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('rar_suggestion_de')->willReturn($collection);

        $this->multiSearch = $this->createMock(MultiSearch::class);
        $this->multiSearch->method('perform')->willReturnCallback(function (array $body): array {
            $this->productSearches = array_merge($this->productSearches, $body['searches']);

            return ['results' => array_map(
                fn (array $search): array => $this->checksFail
                    ? ['code' => 500, 'error' => 'down']
                    : ['found' => in_array($search['q'], $this->productHits, true) ? 4 : 0],
                $body['searches'],
            )];
        });

        $client = $this->createMock(Client::class);
        $client->collections = $collections;
        $client->multiSearch = $this->multiSearch;
        $clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $clientFactory->method('create')->with(3)->willReturn($client);

        $nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $nameResolver->method('resolve')->willReturnCallback(
            static fn (string $entityType, string $storeCode): string => "rar_{$entityType}_{$storeCode}",
        );

        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->config->method('getSuggestionLimit')->willReturnCallback(fn (): int => $this->limit);
        $this->config->method('getBatchSize')->willReturn(200);
        $this->config->method('getSuggestionMaxLength')->willReturn(60);
        $this->config->method('getSuggestionDenylist')->willReturn(['casino']);

        $paramsBuilder = $this->createMock(SearchRequestParamsBuilder::class);
        $paramsBuilder->method('build')
            ->with(SearchRequestParamsBuilder::CONTEXT_SEARCH, 3)
            ->willReturn(['query_by' => 'name,sku', 'prefix' => true]);

        $this->sut = new SuggestionPruner(
            $nameResolver,
            $this->config,
            $clientFactory,
            new SuggestionTermFilter($this->config),
            $paramsBuilder,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function test_marks_terms_by_whether_the_storefront_search_finds_products(): void
    {
        $this->stored = [
            ['id' => '1', 'q' => 'birne', 'count' => 9],
            ['id' => '2', 'q' => 'qqxxzz', 'count' => 7, 'is_valid' => true],
            ['id' => '3', 'q' => 'kirsch', 'count' => 4, 'is_valid' => true],
        ];
        $this->productHits = ['birne', 'kirsch'];

        $updates = [];
        $this->documents->expects(self::once())
            ->method('import')
            ->willReturnCallback(static function (array $documents, array $options) use (&$updates): array {
                $updates = [$documents, $options];

                return array_fill(0, count($documents), ['success' => true]);
            });
        $this->documents->expects(self::never())->method('delete');

        $stats = $this->sut->prune(3, 'de');

        // Only changed flags are written: "kirsch" already is valid.
        self::assertSame(
            [[['id' => '1', 'is_valid' => true], ['id' => '2', 'is_valid' => false]], ['action' => 'update']],
            $updates,
        );
        self::assertSame(['checked' => 3, 'valid' => 2, 'invalid' => 1, 'deleted' => 0], $stats);
        self::assertSame(
            [
                'query_by' => 'name,sku', 'prefix' => true, 'collection' => 'rar_product_de', 'q' => 'birne',
                'filter_by' => 'visibility:[3,4]', 'per_page' => 0, 'enable_analytics' => false,
            ],
            $this->productSearches[0],
        );
    }

    public function test_deletes_denylisted_overlong_and_non_word_terms_without_searching_them(): void
    {
        $this->stored = [
            ['id' => '1', 'q' => 'birne', 'count' => 9, 'is_valid' => true],
            ['id' => '2', 'q' => 'best casino bonus', 'count' => 500],
            ['id' => '3', 'q' => str_repeat('x', 61), 'count' => 80],
            ['id' => '4', 'q' => 'https://spam.example', 'count' => 70],
            ['id' => '5', 'q' => '', 'count' => 1],
        ];
        $this->productHits = ['birne'];

        $this->documents->expects(self::once())
            ->method('delete')
            ->with(['filter_by' => 'id:[2,3,4,5]'])
            ->willReturn(['num_deleted' => 4]);
        $this->documents->expects(self::never())->method('import');

        $stats = $this->sut->prune(3, 'de');

        self::assertSame(['birne'], array_column($this->productSearches, 'q'));
        self::assertSame(['checked' => 5, 'valid' => 1, 'invalid' => 0, 'deleted' => 4], $stats);
    }

    public function test_reads_at_most_the_rule_limit_and_searches_in_multi_search_batches(): void
    {
        for ($i = 0; $i < 400; $i++) {
            $this->stored[] = ['id' => (string) $i, 'q' => 'term ' . $i, 'count' => 1000 - $i, 'is_valid' => false];
        }
        $this->limit = 320;

        $this->multiSearch->expects(self::exactly(7))->method('perform');

        $stats = $this->sut->prune(3, 'de');

        self::assertSame(320, $stats['checked']);
        self::assertCount(320, $this->productSearches);
        self::assertSame('term 319', end($this->productSearches)['q']);
    }

    public function test_a_failed_check_leaves_the_flag_unchanged(): void
    {
        $this->stored = [['id' => '1', 'q' => 'birne', 'count' => 9, 'is_valid' => true]];
        $this->checksFail = true;

        $this->documents->expects(self::never())->method('import');

        self::assertSame(['checked' => 1, 'valid' => 0, 'invalid' => 0, 'deleted' => 0], $this->sut->prune(3, 'de'));
    }
}
