<?php

declare(strict_types=1);

namespace BetterData\Presenter\Formatter;

use BetterData\Presenter\PresentationContext;

/**
 * Thin translation wrapper for use inside Presenter compute closures.
 *
 * Delegates to WP's i18n helpers (`__`, `_x`, `_n`) when available.
 * Falls back to the untranslated string / sprintf fallback otherwise,
 * so the class is safely usable in tests without a WP runtime.
 *
 * Not a deep i18n abstraction — the expectation is that callers pass
 * the text domain their plugin already uses. The formatter just makes
 * the call site terse.
 */
final readonly class I18nFormatter
{
    /**
     * @phpstan-ignore-next-line property.onlyWritten — context reserved for future locale override
     */
    public function __construct(private PresentationContext $context, private string $textDomain = 'default')
    {
    }

    public function t(string $text): string
    {
        if (\function_exists('__')) {
            return \__($text, $this->textDomain);
        }

        return $text;
    }

    public function x(string $text, string $context): string
    {
        if (\function_exists('_x')) {
            return \_x($text, $context, $this->textDomain);
        }

        return $text;
    }

    public function n(string $singular, string $plural, int $count): string
    {
        if (\function_exists('_n')) {
            return \_n($singular, $plural, $count, $this->textDomain);
        }

        return $count === 1 ? $singular : $plural;
    }

    /**
     * Translate a template and substitute positional placeholders.
     */
    public function f(string $template, mixed ...$args): string
    {
        return sprintf($this->t($template), ...$args);
    }
}
