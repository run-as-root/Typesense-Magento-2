<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Suggestion;

use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * Whether a counted query may be shown to other shoppers. Anyone can make Typesense count any text, so long
 * strings, links, addresses, digit runs and denylisted words are never suggested. listing-core.js applies the
 * same rules in the browser (suggestionAllowed).
 */
class SuggestionTermFilter
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function isAllowed(string $term, ?int $storeId = null): bool
    {
        $term = trim($term);
        if ($term === '' || !mb_check_encoding($term, 'UTF-8')
            || mb_strlen($term) > $this->config->getSuggestionMaxLength($storeId)
            || preg_match('#@|://|(^|\s)www\.#iu', $term) === 1
        ) {
            return false;
        }

        // Mostly non-letters: SKUs, phone numbers, encoded or obfuscated strings.
        if (2 * (int) preg_match_all('/\p{L}/u', $term) < (int) preg_match_all('/\S/u', $term)) {
            return false;
        }

        $lower = mb_strtolower($term);
        $denylist = $this->config->getSuggestionDenylist($storeId);
        foreach ($denylist as $blocked) {
            if (str_contains($lower, $blocked)) {
                return false;
            }
        }

        return true;
    }
}
