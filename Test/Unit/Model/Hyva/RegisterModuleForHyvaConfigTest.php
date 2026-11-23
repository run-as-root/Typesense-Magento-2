<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Hyva;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Hyva\RegisterModuleForHyvaConfig;

final class RegisterModuleForHyvaConfigTest extends TestCase
{
    public function test_adds_module_path_relative_to_magento_root_to_tailwind_extensions(): void
    {
        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPath')
            ->with(ComponentRegistrar::MODULE, 'RunAsRoot_TypeSense')
            ->willReturn('/var/www/html/vendor/run-as-root/magento2-typesense');
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn('/var/www/html');
        $config = new DataObject(['extensions' => [['src' => 'vendor/hyva-themes/other']]]);

        (new RegisterModuleForHyvaConfig($registrar, $directoryList))->execute(new Observer(['config' => $config]));

        self::assertSame([
            ['src' => 'vendor/hyva-themes/other'],
            ['src' => 'vendor/run-as-root/magento2-typesense'],
        ], $config->getData('extensions'));
    }
}
