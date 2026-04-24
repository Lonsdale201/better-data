<?php

declare(strict_types=1);

namespace BetterData\Sink;

use BackedEnum;
use BetterData\DataObject;
use BetterData\Exception\UnknownFieldException;
use BetterData\Secret;
use DateTimeInterface;
use JsonSerializable;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Writes DataObjects back to the WordPress Options API.
 *
 * Options are stored as a single associative array under one option key.
 * OptionSink walks the DTO's public properties directly (not via
 * `toArray()`) so that rich-typed values are unwrapped for storage
 * rather than redacted:
 *
 *  - `Secret` → `->reveal()` — raw value goes to the option store
 *    (same semantic as SinkProjection uses for meta writes).
 *  - `BackedEnum` → its scalar `value`.
 *  - `DateTimeInterface` → ISO 8601 / ATOM string.
 *  - Nested `DataObject` → projected recursively.
 *  - Primitive values / arrays → stored as-is.
 *
 * Partial updates: `$only` restricts which fields are written. The
 * existing option value is read first and merged with the projection,
 * so untouched fields stay as they were.
 *
 * ## Scope — at-rest encryption
 *
 * `#[MetaKey(encrypt: true)]` applies to the meta write path only.
 * OptionSink does NOT currently encrypt at rest — a `Secret` property
 * lands in wp_options as plaintext within the serialized array. The
 * `Secret` type still prevents accidental leaks through serialization
 * and presentation paths, but the on-disk wp_options row is readable
 * by anyone with DB access. If you need at-rest encryption for
 * option-backed secrets, store them as meta on a dedicated config
 * post, or write an application-level encrypt/decrypt wrapper.
 */
final class OptionSink
{
    /**
     * Projected array suitable for `update_option` / caller inspection.
     *
     * Unlike `DataObject::toArray()`, this path UNWRAPS `Secret` values
     * via `->reveal()` because options are a write destination, not a
     * presentation output. Leaving `'***'` in storage would be a silent
     * data-loss bug on round-trip.
     *
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public static function toArray(DataObject $dto, ?array $only = null, bool $strict = false): array
    {
        if ($strict && $only !== null) {
            self::assertKnownFields($dto, $only);
        }

        $data = self::projectForStorage($dto);

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
     * Walk public non-static properties and produce an
     * option-storage-ready array. Symmetric with `SinkProjection` for
     * meta: rich types get unwrapped so reads can reconstruct them.
     *
     * @return array<string, mixed>
     */
    private static function projectForStorage(DataObject $dto): array
    {
        $reflection = new ReflectionClass($dto);
        $out = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $out[$property->getName()] = self::projectValue($property->getValue($dto));
        }

        return $out;
    }

    private static function projectValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Secret) {
            return $value->reveal();
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof DataObject) {
            return self::projectForStorage($value);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::projectValue($v), $value);
        }
        if ($value instanceof JsonSerializable) {
            // Last-resort fallback for other serializable wrapper types —
            // honour their declared shape.
            return $value->jsonSerialize();
        }

        return $value;
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
