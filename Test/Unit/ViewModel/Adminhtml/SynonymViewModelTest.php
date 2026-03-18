<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Adminhtml;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\ViewModel\Adminhtml\SynonymViewModel;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Synonyms;

final class SynonymViewModelTest extends TestCase
{
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private LoggerInterface&MockObject $logger;
    private SynonymViewModel $sut;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->sut = new SynonymViewModel(
            $this->clientFactory,
            $this->logger,
        );
    }

    public function test_get_synonyms_returns_synonyms_array_on_success(): void
    {
        $expectedSynonyms = [
            ['id' => 'syn_1', 'synonyms' => ['phone', 'mobile', 'cell']],
            ['id' => 'syn_2', 'root' => 'smartphone', 'synonyms' => ['phone', 'mobile']],
        ];

        $synonyms = $this->createMock(Synonyms::class);
        $synonyms->method('retrieve')->willReturn(['synonyms' => $expectedSynonyms]);

        $collection = $this->createMock(Collection::class);
        $collection->synonyms = $synonyms;

        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('products')->willReturn($collection);
        $collections->method('offsetExists')->willReturn(true);

        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $this->clientFactory->method('create')->willReturn($client);

        $result = $this->sut->getSynonyms('products');

        self::assertSame($expectedSynonyms, $result);
    }

    public function test_get_synonyms_returns_empty_array_on_exception(): void
    {
        $synonyms = $this->createMock(Synonyms::class);
        $synonyms->method('retrieve')->willThrowException(new \Exception('Collection not found'));

        $collection = $this->createMock(Collection::class);
        $collection->synonyms = $synonyms;

        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('nonexistent')->willReturn($collection);
        $collections->method('offsetExists')->willReturn(true);

        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $this->clientFactory->method('create')->willReturn($client);
        $this->logger->expects(self::once())->method('error');

        $result = $this->sut->getSynonyms('nonexistent');

        self::assertSame([], $result);
    }

    public function test_get_synonyms_returns_empty_array_when_synonyms_key_missing(): void
    {
        $synonyms = $this->createMock(Synonyms::class);
        $synonyms->method('retrieve')->willReturn([]);

        $collection = $this->createMock(Collection::class);
        $collection->synonyms = $synonyms;

        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('products')->willReturn($collection);
        $collections->method('offsetExists')->willReturn(true);

        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $this->clientFactory->method('create')->willReturn($client);

        $result = $this->sut->getSynonyms('products');

        self::assertSame([], $result);
    }
}
