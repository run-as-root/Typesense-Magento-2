<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Suggestion;

use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Analytics rules in the Typesense 29 format (`/analytics/rules`, typesense-php's analyticsV1). Typesense 30
 * changed the rule structure.
 */
class AnalyticsRuleManager
{
    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
    ) {
    }

    /**
     * Writes the rule only when it is missing or differs: an upsert drops the counts Typesense has aggregated but
     * not yet flushed to the destination collection.
     *
     * @param array{type: string, params: array<string, mixed>} $rule
     * @return bool Whether the rule was written
     */
    public function ensure(string $name, array $rule, ?int $storeId = null): bool
    {
        $rules = $this->clientFactory->create($storeId)->analyticsV1->rules();

        try {
            $existing = $rules[$name]->retrieve();
        } catch (ObjectNotFound) {
            $existing = null;
        }

        if (is_array($existing) && $this->matches($existing, $rule)) {
            return false;
        }

        $rules->upsert($name, $rule);

        return true;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{type: string, params: array<string, mixed>} $rule
     */
    private function matches(array $existing, array $rule): bool
    {
        $params = is_array($existing['params'] ?? null) ? $existing['params'] : [];

        return ($existing['type'] ?? null) === $rule['type']
            && array_replace_recursive($params, $rule['params']) == $params;
    }
}
