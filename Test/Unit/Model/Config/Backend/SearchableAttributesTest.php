<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Backend\SearchableAttributes;

final class SearchableAttributesTest extends TestCase
{
    private SearchableAttributes $sut;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $this->sut = new SearchableAttributes(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            [],
            new Json(),
        );
    }

    public function test_backend_sorts_rows_by_weight_descending_and_defaults_missing_weight_to_one(): void
    {
        $this->sut->setValue([
            '_1' => ['attribute' => 'sku', 'weight' => '9'],
            '_2' => ['attribute' => 'description'],
            '_3' => ['attribute' => 'name', 'weight' => '10'],
            '_4' => ['attribute' => 'short_description', 'weight' => ''],
            '__empty' => '',
        ]);

        $this->sut->beforeSave();

        self::assertSame(
            [
                '_3' => ['attribute' => 'name', 'weight' => 10],
                '_1' => ['attribute' => 'sku', 'weight' => 9],
                '_2' => ['attribute' => 'description', 'weight' => 1],
                '_4' => ['attribute' => 'short_description', 'weight' => 1],
            ],
            json_decode((string) $this->sut->getValue(), true),
        );
    }
}
