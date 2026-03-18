<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface AliasManagerInterface
{
    public function upsertAlias(string $aliasName, string $collectionName): array;
    public function getAlias(string $aliasName): array;
    public function deleteAlias(string $aliasName): array;
    public function listAliases(): array;
    public function aliasExists(string $aliasName): bool;
}
