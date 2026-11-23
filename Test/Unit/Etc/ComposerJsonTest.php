<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

final class ComposerJsonTest extends TestCase
{
    public function test_requires_the_inventory_api_packages_the_indexer_calls(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);

        self::assertArrayHasKey('magento/module-inventory-sales-api', $composer['require']);
        self::assertArrayHasKey('magento/module-inventory-configuration-api', $composer['require']);
    }
}
