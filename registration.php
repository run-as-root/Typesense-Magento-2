<?php

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

if (!class_exists(ComponentRegistrar::class)) {
    return;
}

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'RunAsRoot_TypeSense',
    __DIR__
);
