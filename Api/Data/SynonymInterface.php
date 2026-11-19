<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api\Data;

interface SynonymInterface
{
    public const TYPE_MULTI = 'multi';
    public const TYPE_ONE_WAY = 'one-way';

    public function getId(): ?int;

    public function getCollectionName(): string;

    public function setCollectionName(string $collection): self;

    public function getTypesenseId(): string;

    public function setTypesenseId(string $typesenseId): self;

    public function getType(): string;

    public function setType(string $type): self;

    public function getRoot(): ?string;

    public function setRoot(?string $root): self;

    /**
     * @return string[]
     */
    public function getSynonyms(): array;

    /**
     * @param string[] $synonyms
     */
    public function setSynonyms(array $synonyms): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
