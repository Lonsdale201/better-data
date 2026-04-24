<?php

declare(strict_types=1);

namespace BetterData\Presenter\Formatter;

use BetterData\Presenter\PresentationContext;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Locale/timezone-aware DateTime formatter for use inside Presenter
 * compute closures (or standalone).
 *
 * - Applies the context's timezone (if set) before formatting, so the
 *   displayed time is in the user's intended zone.
 * - Uses `wp_date()` when available — which gives translated month /
 *   day names via the site locale for tokens like `F`, `l`, `M`, `D`.
 * - Falls back to `DateTimeImmutable::format()` when running outside WP
 *   (tests / CLI scripts), so the class stays unit-testable.
 */
final readonly class DateTimeFormatter
{
    public function __construct(private PresentationContext $context)
    {
    }

    public function format(DateTimeInterface $value, string $format = 'Y-m-d H:i:s'): string
    {
        $instance = DateTimeImmutable::createFromInterface($value);

        if ($this->context->timezone !== null) {
            $instance = $instance->setTimezone(new DateTimeZone($this->context->timezone));
        }

        $callback = function () use ($format, $instance): string {
            if (\function_exists('wp_date')) {
                $result = \wp_date($format, $instance->getTimestamp(), $instance->getTimezone());

                return $result !== false ? $result : $instance->format($format);
            }

            return $instance->format($format);
        };

        return LocaleScope::runIn($this->context->locale, $callback);
    }
}
