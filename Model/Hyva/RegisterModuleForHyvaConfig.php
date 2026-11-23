<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Hyva;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Adds the module to app/etc/hyva-themes.json so Hyvä themes compile the Tailwind classes of its templates.
 */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    private const string MODULE_NAME = 'RunAsRoot_TypeSense';

    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly DirectoryList $directoryList,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $config = $observer->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];
        $path = (string) $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);

        // hyva-themes.json holds paths relative to the Magento root.
        $extensions[] = ['src' => substr($path, strlen($this->directoryList->getRoot()) + 1)];

        $config->setData('extensions', $extensions);
    }
}
