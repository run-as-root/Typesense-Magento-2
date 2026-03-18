<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Merchandising;

use Magento\Framework\Model\AbstractModel;
use RunAsRoot\TypeSense\Api\Data\LandingPageInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\LandingPage as LandingPageResource;

class LandingPage extends AbstractModel implements LandingPageInterface
{
    protected function _construct(): void
    {
        $this->_init(LandingPageResource::class);
    }

    public function getId(): ?int
    {
        $id = $this->getData('id');
        return $id !== null ? (int) $id : null;
    }

    public function getUrlKey(): string
    {
        return (string) $this->getData('url_key');
    }

    public function setUrlKey(string $urlKey): self
    {
        return $this->setData('url_key', $urlKey);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function getTitle(): string
    {
        return (string) $this->getData('title');
    }

    public function setTitle(string $title): self
    {
        return $this->setData('title', $title);
    }

    public function getMetaDescription(): ?string
    {
        $value = $this->getData('meta_description');
        return $value !== null ? (string) $value : null;
    }

    public function setMetaDescription(?string $metaDescription): self
    {
        return $this->setData('meta_description', $metaDescription);
    }

    public function getQuery(): string
    {
        return (string) $this->getData('query');
    }

    public function setQuery(string $query): self
    {
        return $this->setData('query', $query);
    }

    public function getFilterBy(): ?string
    {
        $value = $this->getData('filter_by');
        return $value !== null ? (string) $value : null;
    }

    public function setFilterBy(?string $filterBy): self
    {
        return $this->setData('filter_by', $filterBy);
    }

    public function getSortBy(): ?string
    {
        $value = $this->getData('sort_by');
        return $value !== null ? (string) $value : null;
    }

    public function setSortBy(?string $sortBy): self
    {
        return $this->setData('sort_by', $sortBy);
    }

    public function getIncludes(): array
    {
        $value = $this->getData('includes');
        return $value ? json_decode($value, true) : [];
    }

    public function setIncludes(array $includes): self
    {
        return $this->setData('includes', json_encode($includes));
    }

    public function getExcludes(): array
    {
        $value = $this->getData('excludes');
        return $value ? json_decode($value, true) : [];
    }

    public function setExcludes(array $excludes): self
    {
        return $this->setData('excludes', json_encode($excludes));
    }

    public function getBannerConfig(): array
    {
        $value = $this->getData('banner_config');
        return $value ? json_decode($value, true) : [];
    }

    public function setBannerConfig(array $bannerConfig): self
    {
        return $this->setData('banner_config', json_encode($bannerConfig));
    }

    public function getCmsContent(): ?string
    {
        $value = $this->getData('cms_content');
        return $value !== null ? (string) $value : null;
    }

    public function setCmsContent(?string $cmsContent): self
    {
        return $this->setData('cms_content', $cmsContent);
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
