<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Merchandising;

use Magento\Framework\Model\AbstractModel;
use RunAsRoot\TypeSense\Api\Data\QueryMerchandisingInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\QueryMerchandising as QueryMerchandisingResource;

class QueryMerchandising extends AbstractModel implements QueryMerchandisingInterface
{
    protected function _construct(): void
    {
        $this->_init(QueryMerchandisingResource::class);
    }

    public function getId(): ?int
    {
        $id = $this->getData('id');
        return $id !== null ? (int) $id : null;
    }

    public function getQuery(): string
    {
        return (string) $this->getData('query');
    }

    public function setQuery(string $query): self
    {
        return $this->setData('query', $query);
    }

    public function getMatchType(): string
    {
        return (string) $this->getData('match_type');
    }

    public function setMatchType(string $matchType): self
    {
        return $this->setData('match_type', $matchType);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function getIncludes(): array
    {
        $value = $this->getData('includes');

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true) ?? [];
    }

    public function setIncludes(array $includes): self
    {
        return $this->setData('includes', json_encode($includes));
    }

    public function getExcludes(): array
    {
        $value = $this->getData('excludes');

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true) ?? [];
    }

    public function setExcludes(array $excludes): self
    {
        return $this->setData('excludes', json_encode($excludes));
    }

    public function getBannerConfig(): array
    {
        $value = $this->getData('banner_config');

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true) ?? [];
    }

    public function setBannerConfig(array $bannerConfig): self
    {
        return $this->setData('banner_config', json_encode($bannerConfig));
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData('is_active', $isActive);
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
