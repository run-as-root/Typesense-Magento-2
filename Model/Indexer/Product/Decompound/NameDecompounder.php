<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Decompound;

/**
 * Follows every German compound noun of a product name with its tails
 * ("Treppensackkarre bis 200 kg" -> "Treppensackkarre Sackkarre Karre bis 200 kg").
 */
class NameDecompounder
{
    public const string FIELD = 'name_decompounded';

    // Letters, optionally joined by hyphens ("AB-Roller").
    private const string WORD_PATTERN = '/\p{L}+(?:-\p{L}+)*/u';

    public function __construct(
        private readonly CompoundTailExtractor $tailExtractor,
        private readonly CatalogWordsProvider $catalogWordsProvider,
    ) {
    }

    /**
     * @param string $skipWordsSource text whose words are names, not compounds (a product line such as
     *                                "Blackpool" must not yield "Pool")
     */
    public function decompound(string $name, string $skipWordsSource, int $storeId): string
    {
        $originalWords = $this->getLowerCaseWords($name);
        $skipWords = $this->getLowerCaseWords($skipWordsSource);
        $catalogWords = $this->catalogWordsProvider->getWords($storeId);

        $added = [];

        $result = preg_replace_callback(
            self::WORD_PATTERN,
            function (array $match) use ($originalWords, $skipWords, $catalogWords, &$added): string {
                $word = $match[0];

                // German nouns are capitalised; lowercase words (adjectives, verbs) are left alone.
                if (preg_match('/^\p{Lu}/u', $word) !== 1 || isset($skipWords[mb_strtolower($word)])) {
                    return $word;
                }

                $newTails = [];
                $tails = $this->tailExtractor->getTails($word, $catalogWords);
                foreach ($tails as $tail) {
                    $key = mb_strtolower($tail);
                    if (isset($added[$key]) || isset($originalWords[$key])) {
                        continue;
                    }
                    $added[$key] = true;
                    $newTails[] = $tail;
                }

                return $newTails === [] ? $word : $word . ' ' . implode(' ', $newTails);
            },
            $name,
        );

        return $result ?? $name;
    }

    /** @return array<string, true> */
    private function getLowerCaseWords(string $text): array
    {
        $words = [];
        if (preg_match_all(self::WORD_PATTERN, $text, $matches) > 0) {
            foreach ($matches[0] as $word) {
                $words[mb_strtolower($word)] = true;
            }
        }

        return $words;
    }
}
