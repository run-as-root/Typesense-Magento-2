<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface OverrideManagerInterface
{
    public function createOverride(string $collection, string $overrideId, array $rule): array;

    public function deleteOverride(string $collection, string $overrideId): array;

    public function listOverrides(string $collection): array;
}
