<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Product\NewProductResolver;

final class NewProductResolverTest extends TestCase
{
    private TimezoneInterface&MockObject $timezone;
    private NewProductResolver $sut;

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('scopeDate')->with(3)->willReturn(new \DateTime('2026-11-20 00:00:00'));
        $this->sut = new NewProductResolver($this->timezone);
    }

    #[DataProvider('newsWindows')]
    public function test_is_new_true_only_inside_news_date_window(?string $from, ?string $to, bool $expected): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnMap([
            ['news_from_date', null, $from],
            ['news_to_date', null, $to],
        ]);

        self::assertSame($expected, $this->sut->isNew($product, 3));
    }

    /** @return array<string, array{?string, ?string, bool}> */
    public static function newsWindows(): array
    {
        return [
            'no dates' => [null, null, false],
            'empty strings' => ['', '', false],
            'from today, open end' => ['2026-11-20 00:00:00', null, true],
            'from past, to future' => ['2026-11-01 00:00:00', '2026-12-01 00:00:00', true],
            'to date is today (inclusive)' => ['2026-11-01 00:00:00', '2026-11-20 00:00:00', true],
            'only to date in future' => [null, '2026-12-01 00:00:00', true],
            'from in future' => ['2026-11-21 00:00:00', null, false],
            'window ended yesterday' => ['2026-11-01 00:00:00', '2026-11-19 00:00:00', false],
            'only to date in past' => [null, '2026-11-19 00:00:00', false],
        ];
    }
}
