<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

final class OptimizerConversion
{
    /**
     * @param array<string, mixed> $conditions Magento\Rule asArray() tree of the module's condition classes
     * @param list<string> $notes conversions worth reviewing
     */
    private function __construct(
        public readonly ?string $skipReason,
        public readonly ?string $inactiveReason = null,
        public readonly int $weight = 0,
        public readonly bool $applyToSearch = false,
        public readonly bool $applyToCategory = false,
        public readonly array $conditions = [],
        public readonly array $notes = [],
    ) {
    }

    /**
     * @param array<string, mixed> $conditions
     * @param list<string> $notes
     */
    public static function converted(
        int $weight,
        bool $applyToSearch,
        bool $applyToCategory,
        array $conditions,
        ?string $inactiveReason,
        array $notes = [],
    ): self {
        return new self(null, $inactiveReason, $weight, $applyToSearch, $applyToCategory, $conditions, $notes);
    }

    public static function skipped(string $reason): self
    {
        return new self($reason);
    }
}
