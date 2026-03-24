<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\EmbeddingFieldSource;

final class EmbeddingFieldSourceTest extends TestCase
{
    public function test_to_option_array_contains_name_and_description(): void
    {
        $sut = new EmbeddingFieldSource();
        $options = $sut->toOptionArray();
        $values = array_column($options, 'value');

        self::assertContains('name', $values);
        self::assertContains('description', $values);
    }
}
