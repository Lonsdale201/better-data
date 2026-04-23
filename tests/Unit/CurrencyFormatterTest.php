<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Presenter\Formatter\CurrencyFormatter;
use BetterData\Presenter\PresentationContext;
use PHPUnit\Framework\TestCase;

/**
 * These tests run in the plain-fallback code path (no WooCommerce in
 * the unit runtime). The WC path is exercised by the smoke plugin.
 */
final class CurrencyFormatterTest extends TestCase
{
    public function testFormatsWithPrefixSymbol(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'USD');

        self::assertSame('$199.99', $f->format(199.99));
        self::assertSame('$0.00', $f->format(0));
        self::assertSame('$-50.00', $f->format(-50));
    }

    public function testFormatsWithSuffixSymbolForForint(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'HUF');

        self::assertSame('10,000.00 Ft', $f->format(10000));
    }

    public function testEuroSymbol(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'EUR');

        self::assertSame('€42.50', $f->format(42.5));
    }

    public function testFallsBackToCurrencyCodeForUnknownCurrency(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'XYZ');

        self::assertSame('XYZ100.00', $f->format(100));
    }

    public function testFormatsWithoutCurrencyWhenNoneSet(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none());

        self::assertSame('100.00', $f->format(100));
    }

    public function testPerCallCurrencyOverridesDefault(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'USD');

        self::assertSame('€50.00', $f->format(50, 'EUR'));
    }

    public function testFormatRawReturnsNumericOnly(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'USD');

        self::assertSame('1234.56', $f->formatRaw(1234.56));
        self::assertSame('1 234,56', $f->formatRaw(1234.56, 2, ',', ' '));
        self::assertSame('1000', $f->formatRaw(1000, 0));
    }

    public function testFormatHtmlMatchesFormatWhenWcUnavailable(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'USD');

        // No WC → HTML variant returns the same plain string as format()
        self::assertSame($f->format(99), $f->formatHtml(99));
    }

    public function testThousandsSeparatorInPlainFallback(): void
    {
        $f = new CurrencyFormatter(PresentationContext::none(), 'USD');

        self::assertSame('$1,234,567.89', $f->format(1234567.89));
    }
}
