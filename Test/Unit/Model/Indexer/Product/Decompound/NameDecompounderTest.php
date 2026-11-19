<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product\Decompound;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\CatalogWordsProvider;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\CompoundTailExtractor;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\NameDecompounder;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\SplitterProvider;

final class NameDecompounderTest extends TestCase
{
    private const int STORE_ID = 17;

    private const array TAILS = [
        'Treppensackkarre' => ['Sackkarre', 'Karre'],
        'Sackkarre' => ['Karre'],
        'Heißluftfritteuse' => ['Fritteuse'],
        'AB-Roller' => ['Roller'],
        'Schuhregal' => ['Regal'],
        'Blackpool' => ['Pool'],
        'Sitzgruppe' => ['Gruppe'],
        'Lausanne' => ['Anne'],
    ];

    private CompoundTailExtractor&MockObject $extractor;
    private CatalogWordsProvider&MockObject $catalogWordsProvider;
    private NameDecompounder $sut;

    protected function setUp(): void
    {
        $this->extractor = $this->createMock(CompoundTailExtractor::class);
        $this->extractor->method('getTails')->willReturnCallback(
            static function (string $word): array {
                if (mb_strtolower(mb_substr($word, 0, 1)) === mb_substr($word, 0, 1)) {
                    self::fail('Extractor must not be called for lowercase-first word: ' . $word);
                }

                return self::TAILS[$word] ?? [];
            },
        );
        $this->catalogWordsProvider = $this->createMock(CatalogWordsProvider::class);
        $this->catalogWordsProvider->method('getWords')->willReturn([]);
        $this->sut = new NameDecompounder($this->extractor, $this->catalogWordsProvider);
    }

    public function test_field_name(): void
    {
        self::assertSame('name_decompounded', NameDecompounder::FIELD);
    }

    public function test_inserts_tails_right_after_the_compound_word(): void
    {
        self::assertSame(
            'Treppensackkarre Sackkarre Karre bis 200 kg',
            $this->sut->decompound('Treppensackkarre bis 200 kg', '', self::STORE_ID),
        );
    }

    public function test_name_without_compounds_is_unchanged(): void
    {
        $name = 'Balkonfächer klappbar mit Wandhalterung 140x7,5x140cm';

        self::assertSame($name, $this->sut->decompound($name, '', self::STORE_ID));
    }

    public function test_keeps_punctuation_and_digits_verbatim(): void
    {
        self::assertSame(
            'Heißluftfritteuse Fritteuse, 5,5l Version 1',
            $this->sut->decompound('Heißluftfritteuse, 5,5l Version 1', '', self::STORE_ID),
        );
    }

    public function test_does_not_repeat_tail_already_present_in_name(): void
    {
        self::assertSame(
            'Sackkarre Karre Treppensackkarre',
            $this->sut->decompound('Sackkarre Treppensackkarre', '', self::STORE_ID),
        );
    }

    public function test_hyphenated_word(): void
    {
        self::assertSame(
            'Bauchtrainer AB-Roller Roller',
            $this->sut->decompound('Bauchtrainer AB-Roller', '', self::STORE_ID),
        );
    }

    public function test_empty_name(): void
    {
        self::assertSame('', $this->sut->decompound('', '', self::STORE_ID));
    }

    public function test_skips_words_starting_with_lowercase_letter(): void
    {
        self::assertSame(
            'klappbare Treppensackkarre Sackkarre Karre',
            $this->sut->decompound('klappbare Treppensackkarre', '', self::STORE_ID),
        );
    }

    public function test_does_not_add_tail_present_in_name_as_lowercase_word(): void
    {
        self::assertSame(
            'karre Treppensackkarre Sackkarre',
            $this->sut->decompound('karre Treppensackkarre', '', self::STORE_ID),
        );
    }

    public function test_does_not_decompound_words_of_the_skip_words_source(): void
    {
        self::assertSame(
            'Schuhregal Regal Blackpool 101x30,5x45,5cm',
            $this->sut->decompound('Schuhregal Blackpool 101x30,5x45,5cm', 'Blackpool', self::STORE_ID),
        );
    }

    public function test_skip_words_are_matched_case_insensitively_word_by_word(): void
    {
        self::assertSame(
            'Rattan Sitzgruppe Gruppe Lausanne 2+1',
            $this->sut->decompound('Rattan Sitzgruppe Lausanne 2+1', 'Lausanne-SET lausanne', self::STORE_ID),
        );
    }

    public function test_passes_catalog_words_of_the_store_to_the_extractor(): void
    {
        $catalogWords = ['tisch' => true];
        $catalogWordsProvider = $this->createMock(CatalogWordsProvider::class);
        $catalogWordsProvider->expects(self::once())->method('getWords')->with(self::STORE_ID)
            ->willReturn($catalogWords);
        $extractor = $this->createMock(CompoundTailExtractor::class);
        $extractor->expects(self::once())->method('getTails')->with('Esstisch', $catalogWords)->willReturn(['Tisch']);

        $sut = new NameDecompounder($extractor, $catalogWordsProvider);

        self::assertSame('Esstisch Tisch Swansea', $sut->decompound('Esstisch Swansea', 'Swansea', self::STORE_ID));
    }

    public function test_real_splitter_decompounds_and_leaves_plain_words_alone(): void
    {
        $sut = new NameDecompounder(new CompoundTailExtractor(new SplitterProvider()), $this->catalogWordsProvider);

        self::assertSame(
            'Treppensackkarre Sackkarre Karre bis 200 kg',
            $sut->decompound('Treppensackkarre bis 200 kg', '', self::STORE_ID),
        );
        self::assertSame('Öl Groß 200ml Fuß-Bad', $sut->decompound('Öl Groß 200ml Fuß-Bad', '', self::STORE_ID));
    }
}
