<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Suggestion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Suggestion\SuggestionTermFilter;

final class SuggestionTermFilterTest extends TestCase
{
    private SuggestionTermFilter $sut;

    protected function setUp(): void
    {
        $config = $this->createMock(TypeSenseConfigInterface::class);
        $config->method('getSuggestionMaxLength')->with(3)->willReturn(20);
        $config->method('getSuggestionDenylist')->with(3)->willReturn(['casino', 'ärger']);

        $this->sut = new SuggestionTermFilter($config);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function terms(): array
    {
        return [
            'word' => ['birne', true],
            'words with digits' => ['schnaps 0,7 l', true],
            'umlauts' => ['marillen-likör', true],
            'at the length limit' => [str_repeat('a', 20), true],
            'empty' => ['  ', false],
            'too long' => [str_repeat('a', 21), false],
            'mostly digits' => ['4711 0815', false],
            'url' => ['https://spam.example', false],
            'www' => ['www.spam.example', false],
            'email' => ['me@spam.example', false],
            'denylisted word part, any case' => ['Online-CASINO bonus', false],
            'denylisted multibyte' => ['Ärgernis', false],
            'malformed utf-8' => ["bir\xC3\x28ne", false],
        ];
    }

    #[DataProvider('terms')]
    public function test_only_plain_short_unblocked_terms_are_allowed(string $term, bool $allowed): void
    {
        self::assertSame($allowed, $this->sut->isAllowed($term, 3));
    }
}
