<?php

declare(strict_types=1);

namespace BetterData\Presenter\Formatter;

/**
 * Runs a callback under a temporary WordPress locale override.
 *
 * Wraps `switch_to_locale()` / `restore_previous_locale()` with a
 * finally-block so exceptions inside the callback still restore the
 * previous locale. No-op when the target locale is null, unchanged
 * from the current locale, or when running outside WP.
 *
 * @internal
 */
final class LocaleScope
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runIn(?string $locale, callable $callback): mixed
    {
        if ($locale === null
            || !\function_exists('switch_to_locale')
            || !\function_exists('restore_previous_locale')
            || !\function_exists('get_locale')
        ) {
            return $callback();
        }

        if (\get_locale() === $locale) {
            return $callback();
        }

        $switched = (bool) \switch_to_locale($locale);
        try {
            return $callback();
        } finally {
            if ($switched) {
                \restore_previous_locale();
            }
        }
    }
}
