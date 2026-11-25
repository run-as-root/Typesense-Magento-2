<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Suggestion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Suggestion\AnalyticsQueryKey;

final class AnalyticsQueryKeyTest extends TestCase
{
    private AnalyticsQueryKey $sut;

    protected function setUp(): void
    {
        $this->sut = new AnalyticsQueryKey();
    }

    public function test_normalize_trims_and_lowercases_ascii_only(): void
    {
        self::assertSame('williams birne', $this->sut->normalize("  Williams BIRNE \t"));
        self::assertSame('Äpfel', $this->sut->normalize('Äpfel'));
    }

    /**
     * Reference ids computed with Typesense 29's wyhash_v5.h (StringUtils::hash_wy), covering every length branch.
     *
     * @return array<string, array{string, string}>
     */
    public static function documentIds(): array
    {
        return [
            'empty' => ['', '10425577839352312049'],
            '1 byte' => ['a', '3506025972867339876'],
            '2 bytes' => ['ab', '9743548843926343343'],
            '3 bytes' => ['gin', '393136922600598429'],
            '5 bytes' => ['birne', '10629654979707214948'],
            '6 bytes multibyte' => ['äpfel', '759099409369872003'],
            '9 bytes' => ['zwetschke', '15797738969912522876'],
            '13 bytes' => ['schnaps 0,7 l', '17256070746820200282'],
            '14 bytes' => ['williams birne', '13224864678688027347'],
            '21 bytes' => ['obstbrand-geschenkset', '17889141663145932227'],
            '42 bytes' => ['bergbrennerei loewen williams christ birne', '12755827994978004163'],
            '88 bytes' => [
                'abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnop',
                '18047364944670351910',
            ],
        ];
    }

    #[DataProvider('documentIds')]
    public function test_document_id_matches_the_id_typesense_analytics_assigns(string $query, string $expected): void
    {
        self::assertSame($expected, $this->sut->documentId($query));
    }
}
