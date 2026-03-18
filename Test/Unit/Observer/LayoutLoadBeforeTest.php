<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Observer\LayoutLoadBefore;

final class LayoutLoadBeforeTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private LayoutLoadBefore $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new LayoutLoadBefore($this->config);
    }

    public function test_adds_layout_handle_when_on_category_page_and_config_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);

        $layoutUpdate = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addHandle'])
            ->getMock();
        $layoutUpdate->expects(self::once())
            ->method('addHandle')
            ->with('typesense_category_search');

        $layout = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getUpdate'])
            ->getMock();
        $layout->method('getUpdate')->willReturn($layoutUpdate);

        $observer = $this->createMock(Observer::class);
        $observer->method('getData')
            ->willReturnMap([
                ['full_action_name', null, 'catalog_category_view'],
                ['layout', null, $layout],
            ]);

        $this->sut->execute($observer);
    }

    public function test_does_not_add_handle_when_not_on_category_page(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);

        $layoutUpdate = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addHandle'])
            ->getMock();
        $layoutUpdate->expects(self::never())
            ->method('addHandle');

        $layout = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getUpdate'])
            ->getMock();
        $layout->method('getUpdate')->willReturn($layoutUpdate);

        $observer = $this->createMock(Observer::class);
        $observer->method('getData')
            ->willReturnMap([
                ['full_action_name', null, 'cms_index_index'],
                ['layout', null, $layout],
            ]);

        $this->sut->execute($observer);
    }

    public function test_does_not_add_handle_when_config_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isReplaceCategoryPage')->willReturn(true);

        $observer = $this->createMock(Observer::class);
        $observer->expects(self::never())->method('getData');

        $this->sut->execute($observer);
    }

    public function test_does_not_add_handle_when_replace_category_page_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReplaceCategoryPage')->willReturn(false);

        $observer = $this->createMock(Observer::class);
        $observer->expects(self::never())->method('getData');

        $this->sut->execute($observer);
    }
}
