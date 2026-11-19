<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;

interface SynonymRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(SynonymInterface $synonym): SynonymInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(SynonymInterface $synonym): void;

    /**
     * @return SynonymInterface[]
     */
    public function getByCollection(string $collection): array;

    /**
     * @throws NoSuchEntityException
     */
    public function getByTypesenseId(string $collection, string $typesenseId): SynonymInterface;
}
