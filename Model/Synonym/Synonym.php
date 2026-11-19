<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Synonym;

use Magento\Framework\Model\AbstractModel;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\Synonym as SynonymResource;

class Synonym extends AbstractModel implements SynonymInterface
{
    protected function _construct(): void
    {
        $this->_init(SynonymResource::class);
    }

    public function getId(): ?int
    {
        $id = $this->getData('synonym_id');
        return $id !== null ? (int) $id : null;
    }

    public function getCollectionName(): string
    {
        return (string) $this->getData('collection');
    }

    public function setCollectionName(string $collection): self
    {
        return $this->setData('collection', $collection);
    }

    public function getTypesenseId(): string
    {
        return (string) $this->getData('typesense_id');
    }

    public function setTypesenseId(string $typesenseId): self
    {
        return $this->setData('typesense_id', $typesenseId);
    }

    public function getType(): string
    {
        return (string) $this->getData('type');
    }

    public function setType(string $type): self
    {
        return $this->setData('type', $type);
    }

    public function getRoot(): ?string
    {
        $value = $this->getData('root');
        return $value !== null ? (string) $value : null;
    }

    public function setRoot(?string $root): self
    {
        return $this->setData('root', $root);
    }

    public function getSynonyms(): array
    {
        $decoded = json_decode((string) $this->getData('synonyms'), true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    public function setSynonyms(array $synonyms): self
    {
        return $this->setData('synonyms', json_encode(array_values($synonyms), JSON_THROW_ON_ERROR));
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
