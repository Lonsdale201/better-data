<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\DataObject;
use BetterData\Internal\ColumnMapper;

/**
 * Hydrates DataObjects from `$wpdb` result rows (or any raw DB-style array).
 *
 * WPDB returns every scalar column as a string (plus `NULL` as null). That
 * combination is exactly what the DataObject type coercion layer was built
 * for — RowSource is thin: it normalizes row → array, applies `#[Column]`
 * aliases, and hands the payload to `DataObject::fromArray()`.
 *
 * ```php
 * $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}orders WHERE id = %d", $id), ARRAY_A);
 * $order = RowSource::hydrate($row, OrderDto::class);
 * ```
 *
 * Also supports ARRAY_N style (sequential arrays) when the DTO column
 * names are known; `ARRAY_A` / object row are the recommended inputs.
 */
final class RowSource
{
    /**
     * @template T of DataObject
     * @param array<string, mixed>|object $row
     * @param class-string<T>             $dtoClass
     * @return T
     */
    public static function hydrate(array|object $row, string $dtoClass): DataObject
    {
        $normalized = is_object($row) ? self::objectToArray($row) : $row;
        $mapped = ColumnMapper::applyAliases($dtoClass, $normalized);

        return $dtoClass::fromArray($mapped);
    }

    /**
     * @template T of DataObject
     * @param iterable<array<string, mixed>|object> $rows
     * @param class-string<T>                       $dtoClass
     * @return list<T>
     */
    public static function hydrateMany(iterable $rows, string $dtoClass): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrate($row, $dtoClass);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function objectToArray(object $row): array
    {
        /** @var array<string, mixed> */
        return (array) $row;
    }
}
