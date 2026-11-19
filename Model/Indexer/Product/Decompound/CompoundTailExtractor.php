<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Decompound;

use RunAsRoot\GermanCompoundSplitter\SplitResult;

/**
 * Extracts the tails of a German compound: the substrings from each inner word boundary to the end
 * ("Treppensackkarre" -> "Sackkarre", "Karre"). Leading parts are never returned, because the head of a
 * German compound (its last part) is what the product is.
 *
 * The splitter is statistical and also "splits" plain words ("Waschbar" -> "Bar", "Fenstern" -> "Stern").
 * Missing a split is preferable to such junk, so splits are accepted conservatively; the thresholds were
 * tuned on ~3,200 real product names.
 */
class CompoundTailExtractor
{
    // A split the library scores this high is trusted on its own.
    private const float ACCEPT_SCORE = 0.5;

    // Stricter than ACCEPT_SCORE because it justifies a weak split second-hand: "Hoch|Stuhl" (0.598) justifies
    // "Kinder|Hochstuhl", "Weih|Nachts" (0.53) must not justify "Weihnachts|Baum" (would add "Nachtsbaum").
    private const float CONFIRM_SCORE = 0.57;

    // Between this and ACCEPT_SCORE the word is left alone instead of trying lower-ranked candidates, which
    // would only cut its modifier apart ("Top|Schild" 0.84 -> "Tops|Child" 0.68).
    private const float UNCERTAIN_SCORE = 0.0;

    // Three-part words score low ("Treppensack|Karre" -0.37) although a part is confident ("Treppen|Sack" 0.90).
    private const float FLOOR_SCORE = -0.9;

    // The library scores some common heads low ("Ess|Tisch" -0.15, "Beistell|Tisch" -1.34) because "-tisch"
    // is a frequent adjective ending; a head the catalog uses on its own is accepted down to this score.
    private const float CATALOG_WORD_FLOOR_SCORE = -1.5;

    private const int CANDIDATES = 3;

    private const int MAX_DEPTH = 3;

    // The library does not split shorter words.
    private const int MIN_SPLIT_LENGTH = 6;

    private const int MIN_TAIL_LENGTH = 4;

    // Left parts of 2-3 letters are mostly syllables ("Fen|Stern", "Mat|Rose"), except SHORT_MODIFIERS.
    private const int MIN_MODIFIER_LENGTH = 4;

    private const array SHORT_MODIFIERS = ['bad', 'bar', 'box', 'eis', 'ess', 'fuß', 'hof', 'see', 'tür', 'zug'];

    public function __construct(
        private readonly SplitterProvider $splitterProvider,
    ) {
    }

    /**
     * @param array<string, true> $catalogWords lower-case words the store's product names use on their own
     * @return list<string> tails from longest to shortest, first letter upper-cased, no duplicates
     */
    public function getTails(string $word, array $catalogWords): array
    {
        $lower = mb_strtolower($word, 'UTF-8');

        $hyphen = mb_strrpos($lower, '-', 0, 'UTF-8');
        if ($hyphen !== false) {
            $tail = mb_substr($lower, $hyphen + 1, null, 'UTF-8');
            $offsets = $this->getBoundaries($tail, 1, $catalogWords);
            if (trim(mb_substr($lower, 0, $hyphen, 'UTF-8'), '-') !== '') {
                $offsets[] = 0;
            }

            return $this->collectTails($tail, $offsets);
        }

        return $this->collectTails($lower, $this->getBoundaries($lower, 1, $catalogWords));
    }

    /**
     * @param list<int> $offsets character offsets in $lower
     * @return list<string>
     */
    private function collectTails(string $lower, array $offsets): array
    {
        $offsets = array_unique($offsets);
        sort($offsets);

        $tails = [];
        foreach ($offsets as $offset) {
            $tail = mb_substr($lower, $offset, null, 'UTF-8');
            if (mb_strlen($tail, 'UTF-8') >= self::MIN_TAIL_LENGTH) {
                $tails[] = $this->titleCase($tail);
            }
        }

        return array_values(array_unique($tails));
    }

    /**
     * Inner split offsets of a lower-case word without hyphens.
     *
     * A confident split only recurses into its right part: splitting the modifier would yield tails like
     * "Luftfritteuse" from "Heißluft|Fritteuse". A weak split accepted thanks to a confident part recurses
     * into both, which is how "Treppen|sack|karre" yields "Sackkarre". The catalog-word check runs after the
     * three-part case so "Gartensitz|Bank" still yields "Sitzbank".
     *
     * @param array<string, true> $catalogWords
     * @return list<int>
     */
    private function getBoundaries(string $lower, int $depth, array $catalogWords): array
    {
        if ($depth > self::MAX_DEPTH || mb_strlen($lower, 'UTF-8') < self::MIN_SPLIT_LENGTH) {
            return [];
        }

        $candidates = array_slice($this->splitterProvider->get()->split($lower), 0, self::CANDIDATES);
        foreach ($candidates as $candidate) {
            $boundaries = $this->getCandidateBoundaries($lower, $candidate, $depth, $catalogWords);
            if ($boundaries !== null) {
                return $boundaries;
            }
        }

        return [];
    }

    /**
     * @param array<string, true> $catalogWords
     * @return list<int>|null boundaries of an accepted split, [] to leave the word alone, null to try the
     *                        next candidate
     */
    private function getCandidateBoundaries(
        string $lower,
        SplitResult $candidate,
        int $depth,
        array $catalogWords,
    ): ?array {
        $part1 = mb_strtolower($candidate->part1, 'UTF-8');
        $part2 = mb_strtolower($candidate->part2, 'UTF-8');
        // The library's placeholder for a word without split positions: (0.0, Word, Word).
        if ($part2 === $lower) {
            return [];
        }

        $score = $candidate->score;
        if (!$this->isModifier($part1)) {
            return $score >= self::UNCERTAIN_SCORE ? [] : null;
        }

        $offset = mb_strlen($part1, 'UTF-8');
        if ($score >= self::ACCEPT_SCORE) {
            return $this->acceptSplit($offset, $part2, $depth, $catalogWords);
        }

        if ($this->isThreePartSplit($score, $part1, $part2)) {
            return [
                ...$this->getBoundaries($part1, $depth + 1, $catalogWords),
                ...$this->acceptSplit($offset, $part2, $depth, $catalogWords),
            ];
        }

        if ($score >= self::CATALOG_WORD_FLOOR_SCORE && $this->isCatalogWord($part2, $catalogWords)) {
            return $this->acceptSplit($offset, $part2, $depth, $catalogWords);
        }

        return $score >= self::UNCERTAIN_SCORE ? [] : null;
    }

    /**
     * @param array<string, true> $catalogWords
     * @return list<int> the split at $offset plus the inner boundaries of its right part
     */
    private function acceptSplit(int $offset, string $part2, int $depth, array $catalogWords): array
    {
        return [$offset, ...$this->shift($this->getBoundaries($part2, $depth + 1, $catalogWords), $offset)];
    }

    private function isThreePartSplit(float $score, string $part1, string $part2): bool
    {
        return $score < self::UNCERTAIN_SCORE
            && $score >= self::FLOOR_SCORE
            && ($this->isConfidentCompound($part1) || $this->isConfidentCompound($part2));
    }

    /** @param array<string, true> $catalogWords */
    private function isCatalogWord(string $lowerPart, array $catalogWords): bool
    {
        return mb_strlen($lowerPart, 'UTF-8') >= self::MIN_TAIL_LENGTH && isset($catalogWords[$lowerPart]);
    }

    private function isConfidentCompound(string $lower): bool
    {
        if (mb_strlen($lower, 'UTF-8') < self::MIN_SPLIT_LENGTH) {
            return false;
        }

        $best = $this->splitterProvider->get()->splitBest($lower);

        return $best instanceof SplitResult
            && $best->score >= self::CONFIRM_SCORE
            && $this->isModifier(mb_strtolower($best->part1, 'UTF-8'));
    }

    private function isModifier(string $lowerPart): bool
    {
        return mb_strlen($lowerPart, 'UTF-8') >= self::MIN_MODIFIER_LENGTH
            || in_array($lowerPart, self::SHORT_MODIFIERS, true);
    }

    /**
     * @param list<int> $offsets
     * @return list<int>
     */
    private function shift(array $offsets, int $by): array
    {
        return array_map(static fn (int $offset): int => $offset + $by, $offsets);
    }

    private function titleCase(string $lower): string
    {
        return mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lower, 1, null, 'UTF-8');
    }
}
