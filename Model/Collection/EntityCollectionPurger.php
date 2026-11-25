<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection;

use Magento\Store\Api\StoreRepositoryInterface;
use RunAsRoot\TypeSense\Api\AliasManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;

/**
 * Removes every collection of an entity type: the alias of each store view, its versioned collections and a
 * plain collection from before zero-downtime indexing.
 */
class EntityCollectionPurger
{
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly CollectionManagerInterface $collectionManager,
        private readonly AliasManagerInterface $aliasManager,
    ) {
    }

    /**
     * @return list<string> the deleted aliases (suffixed " (alias)") and collections
     */
    public function purge(string $entityType): array
    {
        $collections = $this->collectionManager->listCollections();
        $collectionNames = array_map(static fn (array $collection): string => (string) $collection['name'], $collections);

        $deleted = [];
        $stores = $this->storeRepository->getList();
        foreach ($stores as $store) {
            if ($store->getCode() === 'admin') {
                continue;
            }

            $aliasName = $this->nameResolver->resolve($entityType, $store->getCode(), (int) $store->getId());
            if ($this->aliasManager->aliasExists($aliasName)) {
                $this->aliasManager->deleteAlias($aliasName);
                $deleted[] = $aliasName . ' (alias)';
            }

            $pattern = '/^' . preg_quote($aliasName, '/') . '(_v\d+)?$/';
            $matching = preg_grep($pattern, $collectionNames) ?: [];
            foreach ($matching as $collectionName) {
                $this->collectionManager->deleteCollection($collectionName);
                $deleted[] = $collectionName;
            }
        }

        return $deleted;
    }
}
