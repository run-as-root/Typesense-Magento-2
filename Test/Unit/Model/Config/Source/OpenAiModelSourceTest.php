<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\OpenAiModelSource;

final class OpenAiModelSourceTest extends TestCase
{
    public function test_to_option_array_returns_openai_models(): void
    {
        $sut = new OpenAiModelSource();
        $options = $sut->toOptionArray();

        self::assertNotEmpty($options);
        self::assertSame('openai/gpt-4o-mini', $options[0]['value']);
    }
}
