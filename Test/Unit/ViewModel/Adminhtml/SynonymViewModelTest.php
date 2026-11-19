<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Adminhtml;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\ViewModel\Adminhtml\SynonymViewModel;

final class SynonymViewModelTest extends TestCase
{
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private SynonymRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private SynonymViewModel $sut;

    protected function setUp(): void
    {
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->repository    = $this->createMock(SynonymRepositoryInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->sut = new SynonymViewModel(
            $this->clientFactory,
            $this->repository,
            $this->logger,
        );
    }

    public function test_get_synonyms_lists_persisted_synonyms_of_the_alias(): void
    {
        $this->repository->method('getByCollection')
            ->with('rar_product_de')
            ->willReturn([
                $this->synonym('syn_1', 'multi', null, ['phone', 'mobile', 'cell']),
                $this->synonym('syn_2', 'one-way', 'smartphone', ['phone', 'mobile']),
            ]);
        $this->clientFactory->expects(self::never())->method('create');

        $result = $this->sut->getSynonyms('rar_product_de_v3');

        self::assertSame(
            [
                ['id' => 'syn_1', 'synonyms' => ['phone', 'mobile', 'cell']],
                ['id' => 'syn_2', 'synonyms' => ['phone', 'mobile'], 'root' => 'smartphone'],
            ],
            $result,
        );
    }

    public function test_get_synonyms_returns_empty_array_on_exception(): void
    {
        $this->repository->method('getByCollection')->willThrowException(new \Exception('db down'));
        $this->logger->expects(self::once())->method('error');

        self::assertSame([], $this->sut->getSynonyms('rar_product_de'));
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
