<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Store;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\GroupRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Store\StoreDataBuilder;

final class StoreDataBuilderTest extends TestCase
{
    public function test_get_document_id_prefixes_store_id(): void
    {
        $sut = new StoreDataBuilder(
            $this->createMock(StoreRepositoryInterface::class),
            $this->createMock(GroupRepositoryInterface::class),
            $this->createMock(WebsiteRepositoryInterface::class),
            $this->createMock(ScopeConfigInterface::class),
        );

        self::assertSame('store_5', $sut->getDocumentId(5));
    }
}
