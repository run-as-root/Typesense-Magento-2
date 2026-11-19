<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Boost;

use Magento\Framework\Model\AbstractModel;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule as BoostRuleResource;

class BoostRule extends AbstractModel implements BoostRuleInterface
{
    protected function _construct(): void
    {
        $this->_init(BoostRuleResource::class);
    }

    public function getId(): ?int
    {
        $id = $this->getData('rule_id');

        return $id !== null ? (int) $id : null;
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    public function setName(string $name): self
    {
        return $this->setData('name', $name);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData('is_active', $isActive);
    }

    public function getWeight(): int
    {
        return (int) $this->getData('weight');
    }

    public function setWeight(int $weight): self
    {
        return $this->setData('weight', $weight);
    }

    public function isApplyToSearch(): bool
    {
        return (bool) $this->getData('apply_to_search');
    }

    public function setApplyToSearch(bool $applyToSearch): self
    {
        return $this->setData('apply_to_search', $applyToSearch);
    }

    public function isApplyToCategory(): bool
    {
        return (bool) $this->getData('apply_to_category');
    }

    public function setApplyToCategory(bool $applyToCategory): self
    {
        return $this->setData('apply_to_category', $applyToCategory);
    }

    public function getFromDate(): ?string
    {
        return $this->getNullableString('from_date');
    }

    public function setFromDate(?string $fromDate): self
    {
        return $this->setData('from_date', $fromDate);
    }

    public function getToDate(): ?string
    {
        return $this->getNullableString('to_date');
    }

    public function setToDate(?string $toDate): self
    {
        return $this->setData('to_date', $toDate);
    }

    public function getConditionsSerialized(): ?string
    {
        return $this->getNullableString('conditions_serialized');
    }

    public function setConditionsSerialized(?string $conditionsSerialized): self
    {
        return $this->setData('conditions_serialized', $conditionsSerialized);
    }

    public function getConditions(): array
    {
        $serialized = $this->getConditionsSerialized();

        if ($serialized === null || $serialized === '') {
            return [];
        }

        $decoded = json_decode($serialized, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getCreatedAt(): ?string
    {
        return $this->getNullableString('created_at');
    }

    public function getUpdatedAt(): ?string
    {
        return $this->getNullableString('updated_at');
    }

    private function getNullableString(string $key): ?string
    {
        $value = $this->getData($key);

        return $value !== null ? (string) $value : null;
    }
}
