<?php

declare(strict_types=1);

namespace BetterData\Sink;

use BetterData\DataObject;

/**
 * Writes DataObjects back to the WordPress Options API.
 *
 * Options are stored as a single associative array under one option key.
 * `OptionSink` is a thin wrapper:
 *  - `toArray` returns the DTO's `toArray()` (honouring `$only`)
 *  - `save` calls `update_option`, with optional autoload flag
 *
 * Partial updates: `$only` restricts which fields are written. The
 * existing option value is read first and merged with the projection,
 * so untouched fields stay as they were.
 */
final class OptionSink
{
    /**
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public static function toArray(DataObject $dto, ?array $only = null): array
    {
        $data = $dto->toArray();
        if ($only === null) {
            return $data;
        }

        $filtered = [];
        foreach ($only as $field) {
            if (array_key_exists($field, $data)) {
                $filtered[$field] = $data[$field];
            }
        }

        return $filtered;
    }

    /**
     * Persist the DTO under an option name. With `$only` set, the
     * existing option array is read first and merged (partial update).
     *
     * @param list<string>|null $only
     */
    public static function save(
        DataObject $dto,
        string $option,
        ?array $only = null,
        ?bool $autoload = null,
    ): bool {
        $projected = self::toArray($dto, $only);

        if ($only !== null) {
            $existing = \get_option($option, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            $projected = array_replace($existing, $projected);
        }

        return (bool) \update_option($option, $projected, $autoload);
    }
}
