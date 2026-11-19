<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api\Data;

interface BoostRuleInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): self;

    /**
     * 0 applies the rule to every store view.
     */
    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function isActive(): bool;

    public function setIsActive(bool $isActive): self;

    public function getWeight(): int;

    public function setWeight(int $weight): self;

    public function isApplyToSearch(): bool;

    public function setApplyToSearch(bool $applyToSearch): self;

    public function isApplyToCategory(): bool;

    public function setApplyToCategory(bool $applyToCategory): self;

    /**
     * Y-m-d, inclusive; null means no lower bound.
     */
    public function getFromDate(): ?string;

    public function setFromDate(?string $fromDate): self;

    /**
     * Y-m-d, inclusive; null means no upper bound.
     */
    public function getToDate(): ?string;

    public function setToDate(?string $toDate): self;

    public function getConditionsSerialized(): ?string;

    public function setConditionsSerialized(?string $conditionsSerialized): self;

    /**
     * Decoded Magento\Rule asArray() tree (combine nodes carry aggregator/conditions, leaves
     * attribute/operator/value); empty when no conditions are stored.
     *
     * @return array<string, mixed>
     */
    public function getConditions(): array;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
