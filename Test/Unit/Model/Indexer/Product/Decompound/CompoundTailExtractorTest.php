<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product\Decompound;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\CompoundTailExtractor;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\SplitterProvider;

/**
 * Runs against the real Splitter (no mock): the expected tails are the tuning target of the algorithm.
 */
final class CompoundTailExtractorTest extends TestCase
{
    private static ?CompoundTailExtractor $sut = null;

    public static function setUpBeforeClass(): void
    {
        self::$sut = new CompoundTailExtractor(new SplitterProvider());
    }

    /** @param list<string> $expected */
    #[DataProvider('wordProvider')]
    public function test_get_tails(string $word, array $expected): void
    {
        self::assertSame($expected, self::$sut->getTails($word, []));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function wordProvider(): array
    {
        return [
            'three-part compound' => ['Treppensackkarre', ['Sackkarre', 'Karre']],
            'two-part compound' => ['Sackkarre', ['Karre']],
            'Gartenstuhl' => ['Gartenstuhl', ['Stuhl']],
            'Klimagerät' => ['Klimagerät', ['Gerät']],
            'short last part dropped' => ['Hundetransportbox', ['Transportbox']],
            'only tail too short' => ['Werkzeugbox', []],
            'confident split keeps modifier whole' => ['Heißluftfritteuse', ['Fritteuse']],
            'lower case input' => ['treppensackkarre', ['Sackkarre', 'Karre']],
            'hyphenated word' => ['AB-Roller', ['Roller']],
            'junk Markierung' => ['Markierung', []],
            'junk Waschbar' => ['Waschbar', []],
            'junk Klappbar' => ['Klappbar', []],
            'junk Gepolstert' => ['Gepolstert', []],
            'junk Geeignet' => ['Geeignet', []],
            'junk Verchromt' => ['Verchromt', []],
            'junk Thorsten' => ['Thorsten', []],
            'junk Chester' => ['Chester', []],
            'shorter than 6' => ['Sack', []],
            'nothing before the hyphen' => ['-fach', []],
            'allowed three-letter modifier' => ['Barstuhl', ['Stuhl']],
            'three-part compound justified by the right part' => ['Kinderhochstuhl', ['Hochstuhl', 'Stuhl']],
            'uncertain split does not cut the modifier' => ['Picknickdecke', ['Decke']],
            'uncertain split does not cut the modifier 2' => ['Werkstattkräne', ['Kräne']],
            'junk Fenstern' => ['Fenstern', []],
            'junk Matrose' => ['Matrose', []],
            'junk Montage' => ['Montage', []],
            'junk Topschild' => ['Topschild', []],
            'junk Weihnachtsbaum' => ['Weihnachtsbaum', []],
            'compound with an English head' => ['Hundepool', ['Pool']],
        ];
    }

    /**
     * @param list<string> $catalogWords
     * @param list<string> $expected
     */
    #[DataProvider('catalogWordsProvider')]
    public function test_weak_split_is_accepted_when_the_tail_is_a_catalog_word(
        string $word,
        array $catalogWords,
        array $expected,
    ): void {
        self::assertSame($expected, self::$sut->getTails($word, array_fill_keys($catalogWords, true)));
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function catalogWordsProvider(): array
    {
        return [
            'Esstisch (-0.15)' => ['Esstisch', ['tisch'], ['Tisch']],
            'Couchtisch (0.16)' => ['Couchtisch', ['tisch'], ['Tisch']],
            'Beistelltisch (-1.34, second candidate)' => ['Beistelltisch', ['tisch'], ['Tisch']],
            'Sonnenliege' => ['Sonnenliege', ['liege'], ['Liege']],
            'no catalog words' => ['Esstisch', [], []],
            'tail is not a catalog word' => ['Esstisch', ['stuhl'], []],
            'catalog word shorter than a tail' => ['Thorsten', ['ten'], []],
            'three-part branch keeps both tails' => ['Gartensitzbank', ['sitzbank', 'bank'], ['Sitzbank', 'Bank']],
            'confident split unchanged' => ['Heißluftfritteuse', ['luftfritteuse', 'fritteuse'], ['Fritteuse']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('edgeWordProvider')]
    public function test_short_digit_hyphen_and_umlaut_words_do_not_throw(string $word, array $expected): void
    {
        self::assertSame($expected, self::$sut->getTails($word, ['bad' => true]));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function edgeWordProvider(): array
    {
        return [
            'short umlaut word' => ['Öl', []],
            'hyphen with short parts' => ['Fuß-Bad', []],
            'digits' => ['200ml', []],
            'sharp s' => ['Groß', []],
            'only a hyphen' => ['-', []],
            'empty' => ['', []],
        ];
    }
}
