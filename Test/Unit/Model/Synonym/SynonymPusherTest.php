<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Synonym;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Synonym\SynonymPusher;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Synonyms;

final class SynonymPusherTest extends TestCase
{
    private Synonyms&MockObject $synonyms;
    private SynonymPusher $sut;

    protected function setUp(): void
    {
        $this->synonyms = $this->createMock(Synonyms::class);

        $collection = $this->createMock(Collection::class);
        $collection->synonyms = $this->synonyms;

        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('rar_product_de_v2')->willReturn($collection);

        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $clientFactory->method('create')->willReturn($client);

        $this->sut = new SynonymPusher($clientFactory);
    }

    public function test_one_way_sends_root_multi_does_not(): void
    {
        $oneWay = $this->synonym('syn_1', 'one-way', 'smartphone', ['phone', 'mobile']);
        $multi = $this->synonym('syn_2', 'multi', 'ignored', ['tv', 'television']);

        $upserts = [];
        $this->synonyms->expects(self::exactly(2))
            ->method('upsert')
            ->willReturnCallback(static function (string $id, array $payload) use (&$upserts): array {
                $upserts[$id] = $payload;

                return [];
            });

        $this->sut->push($oneWay, 'rar_product_de_v2');
        $this->sut->push($multi, 'rar_product_de_v2');

        self::assertSame(
            [
                'syn_1' => ['synonyms' => ['phone', 'mobile'], 'root' => 'smartphone'],
                'syn_2' => ['synonyms' => ['tv', 'television']],
            ],
            $upserts,
        );
    }

    private function synonym(string $id, string $type, ?string $root, array $words): SynonymInterface&MockObject
    {
        $synonym = $this->createMock(SynonymInterface::class);
        $synonym->method('getTypesenseId')->willReturn($id);
        $synonym->method('getType')->willReturn($type);
        $synonym->method('getRoot')->willReturn($root);
        $synonym->method('getSynonyms')->willReturn($words);

        return $synonym;
    }
}
