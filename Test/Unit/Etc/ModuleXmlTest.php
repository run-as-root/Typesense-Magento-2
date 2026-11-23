<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

final class ModuleXmlTest extends TestCase
{
    /**
     * Observers run in module order. Ours must add typesense_autocomplete after Hyva_Theme added hyva_default,
     * so the header-search template set by typesense_autocomplete.xml is merged after Hyva_SmileElasticsuite's.
     */
    public function test_module_loads_after_hyva_theme(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/module.xml');
        self::assertNotFalse($xml);

        self::assertCount(1, $xml->xpath('/config/module[@name="RunAsRoot_TypeSense"]/sequence/module[@name="Hyva_Theme"]'));
    }
}
