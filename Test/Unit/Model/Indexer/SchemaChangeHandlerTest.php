<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer;

use Magento\Framework\App\Cache\TypeListInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Indexer\SchemaChangeHandler;
use RunAsRoot\TypeSense\Model\Listing\CollectionFieldProvider;

final class SchemaChangeHandlerTest extends TestCase
{
    private CollectionFieldProvider&MockObject $fieldProvider;
    private TypeListInterface&MockObject $cacheTypeList;
    private LoggerInterface&MockObject $logger;
    private SchemaChangeHandler $sut;

    protected function setUp(): void
    {
        $this->fieldProvider = $this->createMock(CollectionFieldProvider::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sut = new SchemaChangeHandler($this->fieldProvider, $this->cacheTypeList, $this->logger);
    }

    public function test_forgets_the_fields_of_every_changed_collection_and_cleans_the_full_page_cache_once(): void
    {
        $invalidated = [];
        $this->fieldProvider->method('invalidate')->willReturnCallback(
            static function (string $collection) use (&$invalidated): void {
                $invalidated[] = $collection;
            },
        );
        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('full_page');

        $this->sut->handle(['rar_product_de', 'rar_product_at']);

        self::assertSame(['rar_product_de', 'rar_product_at'], $invalidated);
    }

    public function test_does_nothing_without_changed_collections(): void
    {
        $this->fieldProvider->expects(self::never())->method('invalidate');
        $this->cacheTypeList->expects(self::never())->method('cleanType');

        $this->sut->handle([]);
    }

    public function test_a_failing_cache_clean_is_logged_not_thrown(): void
    {
        $this->cacheTypeList->method('cleanType')->willThrowException(new \RuntimeException('redis down'));

        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('redis down'));

        $this->sut->handle(['rar_product_de']);
    }
}
