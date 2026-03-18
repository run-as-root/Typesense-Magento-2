<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Merchandising;

use Magento\Framework\Model\AbstractModel;
use RunAsRoot\TypeSense\Api\Data\CategoryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryMerchandising as CategoryMerchandisingResource;

class CategoryMerchandising extends AbstractModel implements CategoryMerchandisingInterface
{
    protected function _construct(): void
    {
        $this->_init(CategoryMerchandisingResource::class);
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

    public function getProductId(): int
    {
        return (int) $this->getData('product_id');
    }

    public function setProductId(int $productId): self
    {
        return $this->setData('product_id', $productId);
    }

    public function getPosition(): int
    {
        return (int) $this->getData('position');
    }

    public function setPosition(int $position): self
    {
        return $this->setData('position', $position);
    }

    public function getAction(): string
    {
        return (string) $this->getData('action');
    }

    public function setAction(string $action): self
    {
        return $this->setData('action', $action);
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
