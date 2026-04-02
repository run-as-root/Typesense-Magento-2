<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ToolInterface;
use RunAsRoot\TypeSense\Model\Assistant\ToolRegistry;

final class ToolRegistryTest extends TestCase
{
    public function test_get_tool_returns_registered_tool(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('test_tool');

        $registry = new ToolRegistry(['test_tool' => $tool]);

        self::assertSame($tool, $registry->getTool('test_tool'));
    }

    public function test_get_tool_throws_on_unknown_tool(): void
    {
        $registry = new ToolRegistry([]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->getTool('nonexistent');
    }

    public function test_get_tool_definitions_returns_openai_format(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('my_tool');
        $tool->method('getDescription')->willReturn('Does stuff');
        $tool->method('getParametersSchema')->willReturn([
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ]);

        $registry = new ToolRegistry(['my_tool' => $tool]);
        $definitions = $registry->getToolDefinitions();

        self::assertCount(1, $definitions);
        self::assertSame('function', $definitions[0]['type']);
        self::assertSame('my_tool', $definitions[0]['function']['name']);
        self::assertSame('Does stuff', $definitions[0]['function']['description']);
        self::assertArrayHasKey('properties', $definitions[0]['function']['parameters']);
    }

    public function test_get_tool_definitions_returns_empty_array_when_no_tools(): void
    {
        $registry = new ToolRegistry([]);

        self::assertSame([], $registry->getToolDefinitions());
    }

    public function test_get_tool_definitions_includes_all_tools(): void
    {
        $tool1 = $this->createMock(ToolInterface::class);
        $tool1->method('getName')->willReturn('tool_one');
        $tool1->method('getDescription')->willReturn('First tool');
        $tool1->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);

        $tool2 = $this->createMock(ToolInterface::class);
        $tool2->method('getName')->willReturn('tool_two');
        $tool2->method('getDescription')->willReturn('Second tool');
        $tool2->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);

        $registry = new ToolRegistry(['tool_one' => $tool1, 'tool_two' => $tool2]);
        $definitions = $registry->getToolDefinitions();

        self::assertCount(2, $definitions);
        self::assertSame('tool_one', $definitions[0]['function']['name']);
        self::assertSame('tool_two', $definitions[1]['function']['name']);
    }
}
