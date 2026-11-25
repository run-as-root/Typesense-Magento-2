<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use Magento\Framework\App\Cache\TypeListInterface;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;
use RunAsRoot\TypeSense\Model\Boost\BoostRuleFactory;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\CollectionFactory;

/**
 * Rules are found again by their name prefix "ES optimizer #<id>:", so a re-run updates them. A rule the
 * merchant renamed is no longer recognised and would be imported a second time.
 */
class BoostRuleImporter
{
    private const string NAME_FORMAT = 'ES optimizer #%d: %s';
    private const int NAME_MAX_LENGTH = 255;
    // The rules are rendered into the storefront search config, which the full page cache keeps.
    private const string FULL_PAGE_CACHE_TYPE = 'full_page';

    public function __construct(
        private readonly ElasticSuiteReader $reader,
        private readonly OptimizerConverter $converter,
        private readonly StoreScope $storeScope,
        private readonly CollectionFactory $collectionFactory,
        private readonly BoostRuleFactory $ruleFactory,
        private readonly BoostRuleRepositoryInterface $repository,
        private readonly TypeListInterface $cacheTypeList,
    ) {
    }

    public function import(ImportReport $report, bool $dryRun, ?string $storeCode): void
    {
        if (!$this->reader->hasTables(ElasticSuiteReader::OPTIMIZER, ElasticSuiteReader::OPTIMIZER_SEARCH_CONTAINER)) {
            $report->note(ImportReport::BOOSTS, 'ElasticSuite optimizer tables not found, nothing to import');

            return;
        }

        $stores = $this->storeScope->getStores($storeCode);
        $storeIds = array_map(static fn ($store): int => (int) $store->getId(), $stores);
        $containers = $this->reader->getOptimizerContainers();
        $limitations = $this->reader->getOptimizerLimitations();
        $optimizers = $this->reader->getOptimizers();

        $hasChanges = false;
        foreach ($optimizers as $optimizer) {
            $id = (int) $optimizer['optimizer_id'];
            $subject = sprintf('optimizer #%d "%s"', $id, $optimizer['name']);
            $storeId = (int) $optimizer['store_id'];

            if (!(bool) $optimizer['is_active']) {
                $report->skipped(ImportReport::BOOSTS, $subject, 'inactive');
                continue;
            }
            // "All store views" stays one rule; it is only imported when no single store view was requested.
            if ($storeId === 0 ? $storeCode !== null : !in_array($storeId, $storeIds, true)) {
                $report->skipped(ImportReport::BOOSTS, $subject, sprintf('store view %d not in scope', $storeId));
                continue;
            }

            $conversion = $this->converter->convert($optimizer, $containers[$id] ?? [], $limitations[$id] ?? null);
            if ($conversion->skipReason !== null) {
                $report->skipped(ImportReport::BOOSTS, $subject, $conversion->skipReason);
                continue;
            }
            foreach ($conversion->notes as $note) {
                $report->note(ImportReport::BOOSTS, sprintf('%s: %s', $subject, $note));
            }
            if ($conversion->inactiveReason !== null) {
                $report->note(
                    ImportReport::BOOSTS,
                    sprintf('%s imported inactive: %s', $subject, $conversion->inactiveReason),
                );
            }

            $hasChanges = $this->upsert($optimizer, $conversion, $report, $dryRun) || $hasChanges;
        }

        if ($hasChanges && !$dryRun) {
            $this->cacheTypeList->cleanType(self::FULL_PAGE_CACHE_TYPE);
        }
    }

    /**
     * @param array<string, mixed> $optimizer
     * @return bool whether the rule is new or changed
     */
    private function upsert(array $optimizer, OptimizerConversion $conversion, ImportReport $report, bool $dryRun): bool
    {
        $id = (int) $optimizer['optimizer_id'];
        $values = [
            'name' => mb_substr(sprintf(self::NAME_FORMAT, $id, $optimizer['name']), 0, self::NAME_MAX_LENGTH),
            'store_id' => (int) $optimizer['store_id'],
            'weight' => $conversion->weight,
            'apply_to_search' => $conversion->applyToSearch,
            'apply_to_category' => $conversion->applyToCategory,
            'from_date' => $this->toDate($optimizer['from_date'] ?? null),
            'to_date' => $this->toDate($optimizer['to_date'] ?? null),
            'conditions_serialized' => json_encode($conversion->conditions, JSON_THROW_ON_ERROR),
        ];

        $rule = $this->findImported($id);
        if ($rule === null) {
            // Only a new rule takes its active state from the import; a merchant may have reviewed and enabled it.
            $rule = $this->ruleFactory->create()->setIsActive($conversion->inactiveReason === null);
            $report->created(ImportReport::BOOSTS);
        } elseif ($this->matches($rule, $values)) {
            $report->unchanged(ImportReport::BOOSTS);

            return false;
        } else {
            $report->updated(ImportReport::BOOSTS);
        }

        if ($dryRun) {
            return true;
        }

        $rule->setName($values['name'])
            ->setStoreId($values['store_id'])
            ->setWeight($values['weight'])
            ->setApplyToSearch($values['apply_to_search'])
            ->setApplyToCategory($values['apply_to_category'])
            ->setFromDate($values['from_date'])
            ->setToDate($values['to_date'])
            ->setConditionsSerialized($values['conditions_serialized']);
        $this->repository->save($rule);

        return true;
    }

    private function findImported(int $optimizerId): ?BoostRuleInterface
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('name', ['like' => sprintf('ES optimizer #%d:%%', $optimizerId)]);
        $collection->setPageSize(1);
        $rule = $collection->getFirstItem();

        return $rule instanceof BoostRuleInterface && $rule->getId() !== null ? $rule : null;
    }

    /**
     * @param array{
     *     name: string,
     *     store_id: int,
     *     weight: int,
     *     apply_to_search: bool,
     *     apply_to_category: bool,
     *     from_date: string|null,
     *     to_date: string|null,
     *     conditions_serialized: string,
     * } $values
     */
    private function matches(BoostRuleInterface $rule, array $values): bool
    {
        return $rule->getName() === $values['name']
            && $rule->getStoreId() === $values['store_id']
            && $rule->getWeight() === $values['weight']
            && $rule->isApplyToSearch() === $values['apply_to_search']
            && $rule->isApplyToCategory() === $values['apply_to_category']
            && $rule->getFromDate() === $values['from_date']
            && $rule->getToDate() === $values['to_date']
            && $rule->getConditionsSerialized() === $values['conditions_serialized'];
    }

    private function toDate(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
