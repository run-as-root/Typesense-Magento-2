<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\Model\AbstractModel;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;

class CategoryVirtualRule extends AbstractModel implements CategoryVirtualRuleInterface
{
    protected function _construct(): void
    {
        $this->_init(CategoryVirtualRuleResource::class);
    }

    public function getId(): ?int
    {
        $id = $this->getData('id');
        return $id !== null ? (int) $id : null;
    }

    public function getCategoryId(): int
    {
        return (int) $this->getData('category_id');
    }

    public function setCategoryId(int $categoryId): self
    {
        return $this->setData('category_id', $categoryId);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function isVirtual(): bool
    {
        return (bool) $this->getData('is_virtual');
    }

    public function setIsVirtual(bool $isVirtual): self
    {
        return $this->setData('is_virtual', $isVirtual);
    }

    public function getConditionsSerialized(): ?string
    {
        $value = $this->getData('conditions_serialized');
        return $value !== null ? (string) $value : null;
    }

    public function setConditionsSerialized(?string $conditions): self
    {
        return $this->setData('conditions_serialized', $conditions);
    }

    public function getVirtualCategoryRootId(): ?int
    {
        $value = $this->getData('virtual_category_root_id');
        return $value !== null ? (int) $value : null;
    }

    public function setVirtualCategoryRootId(?int $rootCategoryId): self
    {
        return $this->setData('virtual_category_root_id', $rootCategoryId);
    }

    public function getCompiledFilterCache(): ?string
    {
        $value = $this->getData('compiled_filter_cache');
        return $value !== null ? (string) $value : null;
    }

    public function setCompiledFilterCache(?string $filter): self
    {
        return $this->setData('compiled_filter_cache', $filter);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData('created_at');
        return $value !== null ? (string) $value : null;
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData('updated_at');
        return $value !== null ? (string) $value : null;
    }
}
