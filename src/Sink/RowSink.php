<?php

declare(strict_types=1);

namespace BetterData\Sink;

use BackedEnum;
use BetterData\Attribute\Column;
use BetterData\Attribute\DateFormat;
use BetterData\DataObject;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ReflectionClass;
use ReflectionParameter;

/**
 * Writes DataObjects back as `$wpdb` row arrays.
 *
 * Unlike PostSink/UserSink/TermSink, RowSink doesn't know your table
 * name, prefix, or multisite context — you pass them in. Projection
 * (`toArray`) returns a column-keyed array honouring `#[Column]`
 * aliases in the reverse direction (property name → column name) and
 * `#[DateFormat]` overrides.
 *
 * Default datetime format: MySQL `Y-m-d H:i:s`, converted to UTC — the
 * conservative default for custom tables (most WP plugins store UTC
 * there). Override per-field with `#[DateFormat('...')]` if your table
 * uses something else (e.g. `'U'` for unix timestamps, `'c'` for ISO).
 *
 * `insert` / `update` convenience wrap `$wpdb->insert` / `$wpdb->update`.
 * `$where` for update is caller-supplied — we don't second-guess the
 * primary-key column.
 */
final class RowSink
{
    /**
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public static function toArray(DataObject $dto, ?array $only = null): array
    {
        $reflection = new ReflectionClass($dto);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $out = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }

            $value = $dto->{$name} ?? null;
            $column = self::resolveColumn($parameter, $name);
            $out[$column] = self::prepareValue($value, $parameter);
        }

        return $out;
    }

    /**
     * @param list<string>|null $only
     * @param list<string>|null $formats wpdb-style format specifiers (`%s`, `%d`, `%f`); passed through to $wpdb
     * @return int number of affected rows
     */
    public static function insert(
        \wpdb $wpdb,
        string $table,
        DataObject $dto,
        ?array $only = null,
        ?array $formats = null,
    ): int {
        $data = self::toArray($dto, $only);
        $result = $wpdb->insert($table, $data, $formats);
        if ($result === false) {
            throw new \RuntimeException(
                'wpdb->insert failed: ' . (string) $wpdb->last_error,
            );
        }

        return (int) $result;
    }

    /**
     * @param array<string, mixed> $where       wpdb-style where clause
     * @param list<string>|null    $only
     * @param list<string>|null    $formats     value formats passed to $wpdb->update
     * @param list<string>|null    $whereFormats where-clause formats
     * @return int number of affected rows
     */
    public static function update(
        \wpdb $wpdb,
        string $table,
        DataObject $dto,
        array $where,
        ?array $only = null,
        ?array $formats = null,
        ?array $whereFormats = null,
    ): int {
        $data = self::toArray($dto, $only);
        $result = $wpdb->update($table, $data, $where, $formats, $whereFormats);
        if ($result === false) {
            throw new \RuntimeException(
                'wpdb->update failed: ' . (string) $wpdb->last_error,
            );
        }

        return (int) $result;
    }

    private static function resolveColumn(ReflectionParameter $parameter, string $propertyName): string
    {
        $attr = $parameter->getAttributes(Column::class)[0] ?? null;
        if ($attr !== null) {
            /** @var Column $instance */
            $instance = $attr->newInstance();

            return $instance->name;
        }

        return $propertyName;
    }

    private static function prepareValue(mixed $value, ReflectionParameter $parameter): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            $format = self::resolveDateFormat($parameter);
            $instance = DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'));

            return $instance->format($format);
        }

        if ($value instanceof DataObject) {
            return $value->toArray();
        }

        return $value;
    }

    private static function resolveDateFormat(ReflectionParameter $parameter): string
    {
        $attr = $parameter->getAttributes(DateFormat::class)[0] ?? null;
        if ($attr !== null) {
            /** @var DateFormat $instance */
            $instance = $attr->newInstance();

            return $instance->format;
        }

        return 'Y-m-d H:i:s';
    }
}
