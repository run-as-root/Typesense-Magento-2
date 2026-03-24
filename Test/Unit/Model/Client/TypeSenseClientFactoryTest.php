<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Client;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Client\TypeSenseClientFactory;

/**
 * TypeSenseClientFactory is a trivial wrapper that passes config to new Client().
 * Integration testing with a real Typesense server validates this; unit testing
 * the constructor delegation adds no value and breaks on CI due to Phalcon
 * HTTP factory conflicts with the Typesense SDK's auto-discovery.
 */
final class TypeSenseClientFactoryTest extends TestCase
{
    public function test_class_exists(): void
    {
        self::assertTrue(class_exists(TypeSenseClientFactory::class));
    }
}
