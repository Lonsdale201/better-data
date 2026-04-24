<?php

declare(strict_types=1);

namespace BetterData\Internal;

use BackedEnum;
use BetterData\Attribute\DateFormat;
use BetterData\Attribute\MetaKey;
use BetterData\DataObject;
use BetterData\Exception\UnknownFieldException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ReflectionClass;
use ReflectionParameter;

/**
 * Common write-side projection logic for Post/User/Term sinks.
 *
 * Walks the DTO constructor, splits fields into system + meta buckets,
 * honours `#[DateFormat]` overrides and per-field exclusion lists, and
 * respects a whitelist (`$only`) for partial updates.
 *
 * Returns:
 *   [
 *     'system'       => [ 'post_title' => ..., 'post_date_gmt' => ... ],
 *     'meta'         => [ '_price' => 199.95, '_sku' => 'X-1' ],
 *     'metaToDelete' => [ '_description' ],   // null values requested for deletion
 *   ]
 *
 * Caller decides what to do with these (wp_insert_post meta_input vs
 * post-save update_post_meta loop, etc.).
 *
 * @internal Not part of the public API.
 */
final class SinkProjection
{
    /**
     * @param class-string            $fieldAttribute       PostField / UserField / TermField
     * @param list<string>            $knownFields          canonical system field names for the record type
     * @param array<string, string>   $propertyAliases      e.g. ['id' => 'ID'] or ['id' => 'term_id']
     * @param list<string>            $excludeSystemFields  fields that must never appear in the output (e.g. user_pass)
     * @param list<string>|null       $only                 property-name whitelist; null = write everything declared
     * @param list<string>            $gmtSystemFields      system field names whose MySQL datetime must be in UTC
     * @param string                  $systemDateFormat     default datetime format for system date fields
     * @param bool                    $strict               when true, throws UnknownFieldException if `$only` names a non-existent property
     * @return array{system: array<string, mixed>, meta: array<string, mixed>, metaToDelete: list<string>}
     */
    public static function project(
        DataObject $dto,
        string $fieldAttribute,
        array $knownFields,
        array $propertyAliases = [],
        array $excludeSystemFields = [],
        ?array $only = null,
        array $gmtSystemFields = [],
        string $systemDateFormat = 'Y-m-d H:i:s',
        bool $strict = false,
    ): array {
        $system = [];
        $meta = [];
        $metaToDelete = [];

        $reflection = new ReflectionClass($dto);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return ['system' => [], 'meta' => [], 'metaToDelete' => []];
        }

        if ($strict && $only !== null) {
            self::assertKnownFields($dto::class, $constructor, $only);
        }

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }

            $value = $dto->{$name} ?? null;

            $metaAttr = $parameter->getAttributes(MetaKey::class)[0] ?? null;
            if ($metaAttr !== null) {
                /** @var MetaKey $instance */
                $instance = $metaAttr->newInstance();
                if ($value === null) {
                    $metaToDelete[] = $instance->key;
                } else {
                    $meta[$instance->key] = self::prepareValue(
                        $value,
                        $parameter,
                        null,
                        false,
                    );
                }
                continue;
            }

            $systemField = self::resolveSystemField(
                $parameter,
                $name,
                $fieldAttribute,
                $knownFields,
                $propertyAliases,
            );
            if ($systemField === null) {
                continue;
            }

            if (in_array($systemField, $excludeSystemFields, true)) {
                continue;
            }

            $isGmt = in_array($systemField, $gmtSystemFields, true);
            $system[$systemField] = self::prepareValue(
                $value,
                $parameter,
                $systemDateFormat,
                $isGmt,
            );
        }

        return [
            'system' => $system,
            'meta' => $meta,
            'metaToDelete' => $metaToDelete,
        ];
    }

    /**
     * @param list<string> $only
     */
    private static function assertKnownFields(
        string $dtoClass,
        \ReflectionMethod $constructor,
        array $only,
    ): void {
        $available = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );
        $unknown = array_values(array_diff($only, $available));
        if ($unknown !== []) {
            throw UnknownFieldException::forFields($dtoClass, $unknown, $available);
        }
    }

    /**
     * @param list<string>          $knownFields
     * @param array<string, string> $propertyAliases
     */
    private static function resolveSystemField(
        ReflectionParameter $parameter,
        string $propertyName,
        string $fieldAttribute,
        array $knownFields,
        array $propertyAliases,
    ): ?string {
        $attr = $parameter->getAttributes($fieldAttribute)[0] ?? null;
        if ($attr !== null) {
            /** @var object{name: string} $instance */
            $instance = $attr->newInstance();

            return $instance->name;
        }

        if (isset($propertyAliases[$propertyName])) {
            return $propertyAliases[$propertyName];
        }

        if (in_array($propertyName, $knownFields, true)) {
            return $propertyName;
        }

        return null;
    }

    private static function prepareValue(
        mixed $value,
        ReflectionParameter $parameter,
        ?string $systemDateFormat,
        bool $forceUtc,
    ): mixed {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            $format = self::resolveDateFormat($parameter, $systemDateFormat);
            $instance = DateTimeImmutable::createFromInterface($value);
            if ($forceUtc) {
                $instance = $instance->setTimezone(new DateTimeZone('UTC'));
            }

            return $instance->format($format);
        }

        if ($value instanceof DataObject) {
            return $value->toArray();
        }

        return $value;
    }

    private static function resolveDateFormat(
        ReflectionParameter $parameter,
        ?string $systemDefault,
    ): string {
        $attr = $parameter->getAttributes(DateFormat::class)[0] ?? null;
        if ($attr !== null) {
            /** @var DateFormat $instance */
            $instance = $attr->newInstance();

            return $instance->format;
        }

        return $systemDefault ?? DateTimeInterface::ATOM;
    }
}
