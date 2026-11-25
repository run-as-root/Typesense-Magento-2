<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Synonym\SynonymFactory;
use RunAsRoot\TypeSense\Model\Synonym\SynonymPusher;

/**
 * ElasticSuite applies its thesaurus to every full-text container, product search and the category autocomplete
 * alike, so the synonyms go to the product and (where categories are indexed) the category collection of each
 * store view. They are keyed by alias like the admin's, so they survive zero-downtime swaps.
 */
class SynonymImporter
{
    public function __construct(
        private readonly ElasticSuiteReader $reader,
        private readonly ThesaurusMapper $mapper,
        private readonly StoreScope $storeScope,
        private readonly CollectionNameResolverInterface $nameResolver,
        private readonly TypeSenseConfigInterface $config,
        private readonly SynonymRepositoryInterface $repository,
        private readonly SynonymFactory $synonymFactory,
        private readonly SynonymPusher $pusher,
    ) {
    }

    public function import(ImportReport $report, bool $dryRun, ?string $storeCode): void
    {
        if (!$this->reader->hasTables(
            ElasticSuiteReader::THESAURUS,
            ElasticSuiteReader::THESAURUS_STORE,
            ElasticSuiteReader::THESAURUS_EXPANDED_TERMS,
        )) {
            $report->note(ImportReport::SYNONYMS, 'ElasticSuite thesaurus tables not found, nothing to import');

            return;
        }

        $mapped = $this->mapper->map(
            $this->reader->getThesauri(),
            $this->reader->getThesaurusStoreIds(),
            $this->reader->getExpandedTerms(),
            $this->reader->getReferenceTerms(),
        );
        foreach ($mapped['skipped'] as $skip) {
            $report->skipped(ImportReport::SYNONYMS, $skip['subject'], $skip['reason']);
        }

        $stores = $this->storeScope->getStores($storeCode);
        foreach ($mapped['definitions'] as $definition) {
            $targets = $this->filterStores($stores, $definition['store_ids']);
            if ($targets === []) {
                $report->skipped(
                    ImportReport::SYNONYMS,
                    $definition['label'],
                    sprintf('store view(s) %s not in scope', implode(', ', $definition['store_ids'])),
                );
                continue;
            }

            foreach ($targets as $store) {
                $collections = $this->getCollections($store);
                foreach ($collections as $collection) {
                    $this->upsert($definition, $collection, $report, $dryRun);
                }
            }
        }
    }

    /**
     * @param list<Store> $stores
     * @param list<int> $storeIds 0 meaning all store views
     * @return list<Store>
     */
    private function filterStores(array $stores, array $storeIds): array
    {
        if (in_array(0, $storeIds, true)) {
            return $stores;
        }

        return array_values(array_filter(
            $stores,
            static fn (Store $store): bool => in_array((int) $store->getId(), $storeIds, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function getCollections(Store $store): array
    {
        $storeId = (int) $store->getId();
        $code = (string) $store->getCode();
        $collections = [$this->nameResolver->resolve('product', $code, $storeId)];
        if ($this->config->isCategoryIndexingEnabled($storeId)) {
            $collections[] = $this->nameResolver->resolve('category', $code, $storeId);
        }

        return $collections;
    }

    /**
     * @param array{typesense_id: string, type: string, root: string|null, synonyms: list<string>} $definition
     */
    private function upsert(array $definition, string $collection, ImportReport $report, bool $dryRun): void
    {
        try {
            $synonym = $this->repository->getByTypesenseId($collection, $definition['typesense_id']);
            $isNew = false;
        } catch (NoSuchEntityException) {
            $synonym = $this->synonymFactory->create()
                ->setCollectionName($collection)
                ->setTypesenseId($definition['typesense_id']);
            $isNew = true;
        }

        $isUnchanged = !$isNew
            && $synonym->getType() === $definition['type']
            && $synonym->getRoot() === $definition['root']
            && $synonym->getSynonyms() === $definition['synonyms'];

        if ($isNew) {
            $report->created(ImportReport::SYNONYMS);
        } elseif ($isUnchanged) {
            $report->unchanged(ImportReport::SYNONYMS);
        } else {
            $report->updated(ImportReport::SYNONYMS);
        }

        if ($dryRun) {
            return;
        }

        if (!$isUnchanged) {
            $synonym->setType($definition['type'])
                ->setRoot($definition['root'])
                ->setSynonyms($definition['synonyms']);
            $this->repository->save($synonym);
        }

        // Pushed even when unchanged, so a synonym whose earlier push failed reaches Typesense.
        try {
            $this->pusher->push($synonym, $collection);
        } catch (\Throwable $e) {
            $report->note(ImportReport::SYNONYMS, sprintf(
                '%s saved for %s but not pushed (%s); it is applied with the next reindex',
                $definition['typesense_id'],
                $collection,
                $e->getMessage(),
            ));
        }
    }
}
