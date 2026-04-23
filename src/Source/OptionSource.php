<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\DataObject;

/**
 * Hydrates DataObjects from the WordPress Options API.
 *
 * Typical WP pattern: plugin settings stored under a single option key as
 * a flat associative array. `OptionSource` reads it and passes it straight
 * to `DataObject::fromArray()`, so the existing coercion and nesting
 * rules apply unchanged.
 *
 * ```php
 * $settings = OptionSource::hydrate('my_plugin_settings', SettingsDto::class);
 * ```
 *
 * If the option is absent or not an array, the supplied `$default` array
 * is used — still passed through hydration so DTO defaults and required-
 * field rules stay consistent.
 */
final class OptionSource
{
    /**
     * @template T of DataObject
     * @param class-string<T>      $dtoClass
     * @param array<string, mixed> $default
     * @return T
     */
    public static function hydrate(string $option, string $dtoClass, array $default = []): DataObject
    {
        $value = \function_exists('get_option') ? \get_option($option, $default) : $default;

        if (!is_array($value)) {
            $value = $default;
        }

        return $dtoClass::fromArray($value);
    }
}
