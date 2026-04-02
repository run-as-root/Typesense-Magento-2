<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

interface ToolInterface
{
    /** Tool name as used in OpenAI function calling (e.g. 'search_typesense') */
    public function getName(): string;

    /** Human-readable description for OpenAI */
    public function getDescription(): string;

    /**
     * OpenAI-compatible JSON schema for parameters.
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array;

    /**
     * Execute the tool with the given arguments (decoded from OpenAI tool_call).
     * @param array<string, mixed> $arguments
     * @return string JSON-encoded result string for OpenAI tool_result
     */
    public function execute(array $arguments): string;
}
