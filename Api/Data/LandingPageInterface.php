<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api\Data;

interface LandingPageInterface
{
    public function getId(): ?int;

    public function getUrlKey(): string;

    public function setUrlKey(string $urlKey): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getTitle(): string;

    public function setTitle(string $title): self;

    public function getMetaDescription(): ?string;

    public function setMetaDescription(?string $metaDescription): self;

    public function getQuery(): string;

    public function setQuery(string $query): self;

    public function getFilterBy(): ?string;

    public function setFilterBy(?string $filterBy): self;

    public function getSortBy(): ?string;

    public function setSortBy(?string $sortBy): self;

    public function getIncludes(): array;

    public function setIncludes(array $includes): self;

    public function getExcludes(): array;

    public function setExcludes(array $excludes): self;

    public function getBannerConfig(): array;

    public function setBannerConfig(array $bannerConfig): self;

    public function getCmsContent(): ?string;

    public function setCmsContent(?string $cmsContent): self;

    public function isActive(): bool;

    public function setIsActive(bool $isActive): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
