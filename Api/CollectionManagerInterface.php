<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface CollectionManagerInterface
{
    public function createCollection(string $name, array $schema): array;
    public function deleteCollection(string $name): array;
    public function getCollection(string $name): array;
    public function listCollections(): array;
    public function collectionExists(string $name): bool;
}
