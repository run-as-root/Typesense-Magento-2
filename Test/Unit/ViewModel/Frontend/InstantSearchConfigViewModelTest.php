<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Frontend\InstantSearchConfigViewModel;

final class InstantSearchConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private InstantSearchConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new InstantSearchConfigViewModel($this->config);
    }

    public function test_is_enabled_returns_true_when_both_module_and_instant_search_are_enabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_instant_search_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isInstantSearchEnabled')->willReturn(false);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_module_is_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isInstantSearchEnabled')->willReturn(true);

        self::assertFalse($this->sut->isEnabled());
    }
}
