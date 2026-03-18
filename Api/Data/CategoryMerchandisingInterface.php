<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api\Data;

interface CategoryMerchandisingInterface
{
    public function getId(): ?int;

    public function getCategoryId(): int;

    public function setCategoryId(int $categoryId): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getProductId(): int;

    public function setProductId(int $productId): self;

    public function getPosition(): int;

    public function setPosition(int $position): self;

    public function getAction(): string;

    public function setAction(string $action): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
