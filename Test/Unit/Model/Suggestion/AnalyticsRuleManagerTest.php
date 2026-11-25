<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Suggestion;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Suggestion\AnalyticsRuleManager;
use Typesense\AnalyticsRulesV1;
use Typesense\AnalyticsRuleV1;
use Typesense\AnalyticsV1;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

final class AnalyticsRuleManagerTest extends TestCase
{
    private const RULE = [
        'type' => 'popular_queries',
        'params' => [
            'source' => ['collections' => ['rar_product_de']],
            'destination' => ['collection' => 'rar_suggestion_de'],
            'expand_query' => true,
            'limit' => 1000,
        ],
    ];

    private AnalyticsRulesV1&MockObject $rules;
    private AnalyticsRuleV1&MockObject $rule;
    private Client&MockObject $client;
    private AnalyticsRuleManager $sut;

    protected function setUp(): void
    {
        $this->rule = $this->createMock(AnalyticsRuleV1::class);
        $this->rules = $this->createMock(AnalyticsRulesV1::class);
        $this->rules->method('offsetGet')->with('rar_suggestion_de_popular')->willReturn($this->rule);

        $analytics = $this->createMock(AnalyticsV1::class);
        $analytics->method('rules')->willReturn($this->rules);

        $this->client = $this->createMock(Client::class);
        $this->client->analyticsV1 = $analytics;

        $clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $clientFactory->method('create')->willReturn($this->client);

        $this->sut = new AnalyticsRuleManager($clientFactory);
    }

    public function test_creates_missing_rule(): void
    {
        $this->rule->method('retrieve')->willThrowException(new ObjectNotFound('Rule not found.'));

        $this->rules->expects(self::once())->method('upsert')->with('rar_suggestion_de_popular', self::RULE);

        self::assertTrue($this->sut->ensure('rar_suggestion_de_popular', self::RULE, 3));
    }

    public function test_leaves_matching_rule_alone_so_unflushed_counts_survive(): void
    {
        // Typesense echoes the rule back with its name, in its own key order.
        $this->rule->method('retrieve')->willReturn([
            'name' => 'rar_suggestion_de_popular',
            'type' => 'popular_queries',
            'params' => [
                'limit' => 1000,
                'destination' => ['collection' => 'rar_suggestion_de'],
                'expand_query' => true,
                'source' => ['collections' => ['rar_product_de']],
            ],
        ]);

        $this->rules->expects(self::never())->method('upsert');

        self::assertFalse($this->sut->ensure('rar_suggestion_de_popular', self::RULE, 3));
    }

    public function test_updates_rule_whose_parameters_differ(): void
    {
        $existing = self::RULE;
        $existing['params']['limit'] = 500;
        $this->rule->method('retrieve')->willReturn(['name' => 'rar_suggestion_de_popular'] + $existing);

        $this->rules->expects(self::once())->method('upsert')->with('rar_suggestion_de_popular', self::RULE);

        self::assertTrue($this->sut->ensure('rar_suggestion_de_popular', self::RULE, 3));
    }

    public function test_updates_rule_pointing_at_another_source(): void
    {
        $existing = self::RULE;
        $existing['params']['source']['collections'] = ['rar_product_de_v3'];
        $this->rule->method('retrieve')->willReturn($existing);

        $this->rules->expects(self::once())->method('upsert');

        self::assertTrue($this->sut->ensure('rar_suggestion_de_popular', self::RULE, 3));
    }

    public function test_uses_the_store_client(): void
    {
        $this->rule->method('retrieve')->willReturn(self::RULE);

        $clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $clientFactory->expects(self::once())->method('create')->with(7)->willReturn($this->client);

        (new AnalyticsRuleManager($clientFactory))->ensure('rar_suggestion_de_popular', self::RULE, 7);
    }
}
