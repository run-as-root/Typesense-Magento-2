<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api\Data;

interface QueryMerchandisingInterface
{
    public function getId(): ?int;

    public function getQuery(): string;

    public function setQuery(string $query): self;

    public function getMatchType(): string;

    public function setMatchType(string $matchType): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getIncludes(): array;

    public function setIncludes(array $includes): self;

    public function getExcludes(): array;

    public function setExcludes(array $excludes): self;

    public function getBannerConfig(): array;

    public function setBannerConfig(array $bannerConfig): self;

    public function isActive(): bool;

    public function setIsActive(bool $isActive): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
