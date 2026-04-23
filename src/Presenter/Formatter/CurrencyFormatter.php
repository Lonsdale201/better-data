<?php

declare(strict_types=1);

namespace BetterData\Presenter\Formatter;

use BetterData\Presenter\PresentationContext;

/**
 * Currency formatter with two-tier implementation.
 *
 * **WooCommerce-aware path** (preferred when WC is loaded):
 *  - Reads the shop's configured currency via `get_woocommerce_currency()`
 *    and decimal/thousand separators via `wc_get_price_decimal_separator()`,
 *    `wc_get_price_thousand_separator()`, `wc_get_price_decimals()`.
 *  - Wraps via `wc_price()` for HTML output (`formatHtml`), or strips
 *    the wrapper for plain-text output (`format`). Safe for CSV, email,
 *    JSON alike.
 *
 * **Plain fallback** (when WC isn't loaded):
 *  - `number_format()` with `.` / `,` defaults, configurable per call.
 *  - Known symbol map for the dozen or so most common currencies,
 *    falls back to the ISO code as a prefix.
 *
 * Negative amounts and zero pass through either path unchanged.
 *
 * Usage:
 *
 *     // Inside a Presenter compute:
 *     ->compute('priceDisplay', fn($dto, $ctx) =>
 *         (new CurrencyFormatter($ctx))->format($dto->price))
 *
 *     // Or via Presenter sugar:
 *     ->formatCurrency('price')
 */
final readonly class CurrencyFormatter
{
    /**
     * @var array<string, string>
     */
    private const SYMBOL_MAP = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'HUF' => 'Ft',
        'JPY' => '¥',
        'CHF' => 'CHF',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'PLN' => 'zł',
        'CZK' => 'Kč',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'RON' => 'lei',
    ];

    public function __construct(
        /** @phpstan-ignore-next-line property.onlyWritten — reserved for future locale-based formatting */
        private PresentationContext $context,
        private ?string $defaultCurrency = null,
    ) {
    }

    /**
     * Format as a plain string (no HTML). Safe for CSV, plaintext email,
     * JSON payloads, shell output.
     */
    public function format(float|int $amount, ?string $currency = null): string
    {
        if ($this->isWooCommerceAvailable()) {
            $html = \wc_price((float) $amount, $this->wcArgs($currency));

            return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $this->formatPlain($amount, $currency);
    }

    /**
     * Format as HTML via `wc_price()` when WooCommerce is available. When
     * it isn't, behaves identically to `format()` — there is no meaningful
     * "fallback HTML" to invent outside WC's conventions.
     */
    public function formatHtml(float|int $amount, ?string $currency = null): string
    {
        if ($this->isWooCommerceAvailable()) {
            return (string) \wc_price((float) $amount, $this->wcArgs($currency));
        }

        return $this->formatPlain($amount, $currency);
    }

    /**
     * Format the numeric part only, no currency symbol. Useful for CSV
     * columns where the currency is a separate column, or for sorting.
     */
    public function formatRaw(float|int $amount, int $decimals = 2, string $decimalSep = '.', string $thousandSep = ''): string
    {
        return number_format((float) $amount, $decimals, $decimalSep, $thousandSep);
    }

    private function formatPlain(float|int $amount, ?string $currency): string
    {
        $code = $currency ?? $this->defaultCurrency;
        $number = number_format((float) $amount, 2, '.', ',');

        if ($code === null || $code === '') {
            return $number;
        }

        $symbol = self::SYMBOL_MAP[strtoupper($code)] ?? $code;

        // Symbols that are conventionally suffixed (trailing-symbol currencies)
        $isSuffixSymbol = in_array(strtoupper($code), ['HUF', 'PLN', 'CZK', 'SEK', 'NOK', 'DKK', 'RON'], true);

        return $isSuffixSymbol
            ? sprintf('%s %s', $number, $symbol)
            : sprintf('%s%s', $symbol, $number);
    }

    /**
     * @return array<string, mixed>
     */
    private function wcArgs(?string $currency): array
    {
        $args = [];
        $code = $currency ?? $this->defaultCurrency;
        if ($code !== null && $code !== '') {
            $args['currency'] = $code;
        }

        return $args;
    }

    private function isWooCommerceAvailable(): bool
    {
        return \function_exists('wc_price') && \function_exists('get_woocommerce_currency');
    }
}
