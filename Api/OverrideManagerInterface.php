<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface OverrideManagerInterface
{
    /**
     * @param string $collection Collection name or alias
     * @param string $overrideId Unique override identifier
     * @param array{rule: array, includes?: array, excludes?: array} $rule Override rule payload
     * @return array The created/updated override
     */
    public function createOverride(string $collection, string $overrideId, array $rule): array;

    /**
     * Deletes an override. No-op if the override does not exist.
     *
     * @param string $collection Collection name or alias
     * @param string $overrideId Override identifier to delete
     * @return array The deleted override, or empty array if not found
     */
    public function deleteOverride(string $collection, string $overrideId): array;

    /**
     * @param string $collection Collection name or alias
     * @return array List of overrides for the collection
     */
    public function listOverrides(string $collection): array;
}
