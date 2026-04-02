<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use RunAsRoot\TypeSense\Model\Assistant\Tool\ToolInterface;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools;

    /** @param array<string, ToolInterface> $tools */
    public function __construct(array $tools = [])
    {
        $this->tools = $tools;
    }

    public function getTool(string $name): ToolInterface
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Tool not found: {$name}");
        }

        return $this->tools[$name];
    }

    /**
     * Generate OpenAI-compatible tool definitions array.
     * @return array<int, array<string, mixed>>
     */
    public function getToolDefinitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParametersSchema(),
                ],
            ];
        }

        return $definitions;
    }
}
