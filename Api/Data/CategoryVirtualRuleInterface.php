<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api\Data;

interface CategoryVirtualRuleInterface
{
    public function getId(): ?int;

    public function getCategoryId(): int;

    public function setCategoryId(int $categoryId): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function isVirtual(): bool;

    public function setIsVirtual(bool $isVirtual): self;

    public function getConditionsSerialized(): ?string;

    public function setConditionsSerialized(?string $conditions): self;

    public function getVirtualCategoryRootId(): ?int;

    public function setVirtualCategoryRootId(?int $rootCategoryId): self;

    public function getCompiledFilterCache(): ?string;

    public function setCompiledFilterCache(?string $filter): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
