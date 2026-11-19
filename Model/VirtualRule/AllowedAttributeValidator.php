<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product as RuleProductCondition;

/**
 * Server-side counterpart of the condition dropdown restriction in Condition\Product: a hand-crafted
 * POST, or a rule saved before an attribute was dropped from "Additional Attributes", could
 * otherwise persist a condition on a field Typesense never indexes.
 */
class AllowedAttributeValidator
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /**
     * Walks a Magento\Rule asArray() tree (a node with "conditions" is a combine, otherwise a leaf).
     *
     * @param array<string, mixed> $conditionsTree
     * @return string[] Unique leaf attribute codes outside the Typesense-filterable allowlist.
     */
    public function findDisallowedAttributes(array $conditionsTree, ?int $storeId = null): array
    {
        $allowed = array_merge(
            RuleProductCondition::CORE_FILTERABLE_ATTRIBUTES,
            $this->config->getAdditionalAttributes($storeId),
        );

        $disallowed = [];
        $attributes = $this->collectLeafAttributes($conditionsTree);

        foreach ($attributes as $attribute) {
            if ($attribute === '') {
                continue;
            }

            if (!in_array($attribute, $allowed, true) && !in_array($attribute, $disallowed, true)) {
                $disallowed[] = $attribute;
            }
        }

        return $disallowed;
    }

    /**
     * @param array<string, mixed> $node
     * @return string[]
     */
    private function collectLeafAttributes(array $node): array
    {
        if (array_key_exists('conditions', $node) && is_array($node['conditions'])) {
            $childAttributes = [];

            foreach ($node['conditions'] as $child) {
                if (is_array($child)) {
                    $childAttributes[] = $this->collectLeafAttributes($child);
                }
            }

            return array_merge([], ...$childAttributes);
        }

        return [(string) ($node['attribute'] ?? '')];
    }
}
