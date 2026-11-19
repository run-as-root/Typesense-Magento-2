<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Collection\Applier;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Model\Collection\Applier\SynonymApplier;
use RunAsRoot\TypeSense\Model\Synonym\SynonymPusher;

final class SynonymApplierTest extends TestCase
{
    private SynonymRepositoryInterface&MockObject $repository;
    private SynonymPusher&MockObject $pusher;
    private CollectionNameResolverInterface&MockObject $nameResolver;
    private LoggerInterface&MockObject $logger;
    private SynonymApplier $sut;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SynonymRepositoryInterface::class);
        $this->pusher = $this->createMock(SynonymPusher::class);
        $this->nameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->nameResolver->method('resolve')->with('product', 'de', 3)->willReturn('rar_product_de');

        $this->sut = new SynonymApplier($this->repository, $this->pusher, $this->nameResolver, $this->logger);
    }

    public function test_pushes_every_persisted_synonym_to_new_collection(): void
    {
        $first = $this->createMock(SynonymInterface::class);
        $second = $this->createMock(SynonymInterface::class);
        $this->repository->method('getByCollection')->with('rar_product_de')->willReturn([$first, $second]);

        $pushed = [];
        $this->pusher->expects(self::exactly(2))
            ->method('push')
            ->willReturnCallback(static function (SynonymInterface $synonym, string $collection) use (&$pushed): void {
                $pushed[] = [$synonym, $collection];
            });

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v2');

        self::assertSame([[$first, 'rar_product_de_v2'], [$second, 'rar_product_de_v2']], $pushed);
    }

    public function test_reads_repository_at_apply_time(): void
    {
        $first = $this->createMock(SynonymInterface::class);
        $second = $this->createMock(SynonymInterface::class);
        $this->repository->expects(self::exactly(2))
            ->method('getByCollection')
            ->with('rar_product_de')
            ->willReturnOnConsecutiveCalls([$first], [$first, $second]);

        $pushCount = 0;
        $this->pusher->method('push')->willReturnCallback(static function () use (&$pushCount): void {
            $pushCount++;
        });

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v2');
        self::assertSame(1, $pushCount);

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v3');
        self::assertSame(3, $pushCount);
    }

    public function test_skips_non_product_entities(): void
    {
        $this->repository->expects(self::never())->method('getByCollection');
        $this->pusher->expects(self::never())->method('push');

        $this->sut->apply('category', 3, 'de', 'rar_category_de_v2');
    }

    public function test_failing_push_does_not_stop_remaining_synonyms(): void
    {
        $first = $this->createMock(SynonymInterface::class);
        $first->method('getTypesenseId')->willReturn('syn_1');
        $second = $this->createMock(SynonymInterface::class);
        $this->repository->method('getByCollection')->willReturn([$first, $second]);

        $this->pusher->expects(self::exactly(2))
            ->method('push')
            ->willReturnCallback(static function (SynonymInterface $synonym) use ($first): void {
                if ($synonym === $first) {
                    throw new \RuntimeException('bad synonym');
                }
            });
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('syn_1'));

        $this->sut->apply('product', 3, 'de', 'rar_product_de_v2');
    }
}
