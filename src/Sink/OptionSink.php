<?php

declare(strict_types=1);

namespace BetterData\Sink;

use BetterData\DataObject;
use BetterData\Exception\UnknownFieldException;
use ReflectionClass;
use ReflectionParameter;

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
    public static function toArray(DataObject $dto, ?array $only = null, bool $strict = false): array
    {
        $data = $dto->toArray();
        if ($only === null) {
            return $data;
        }

        if ($strict) {
            self::assertKnownFields($dto, $only);
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
        bool $strict = false,
    ): bool {
        $projected = self::toArray($dto, $only, $strict);

        if ($only !== null) {
            $existing = \get_option($option, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            $projected = array_replace($existing, $projected);
        }

        return (bool) \update_option($option, $projected, $autoload);
    }

    /**
     * @param list<string> $only
     */
    private static function assertKnownFields(DataObject $dto, array $only): void
    {
        $constructor = (new ReflectionClass($dto))->getConstructor();
        if ($constructor === null) {
            return;
        }
        $available = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );
        $unknown = array_values(array_diff($only, $available));
        if ($unknown !== []) {
            throw UnknownFieldException::forFields($dto::class, $unknown, $available);
        }
    }
}
