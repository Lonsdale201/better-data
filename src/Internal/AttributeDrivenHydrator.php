<?php

declare(strict_types=1);

namespace BetterData\Internal;

use BetterData\Attribute\Encrypted;
use BetterData\Attribute\MetaKey;
use BetterData\DataObject;
use BetterData\Encryption\EncryptionEngine;
use BetterData\Exception\DataObjectException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Generic engine that hydrates a DataObject from any WordPress-style
 * record-plus-metadata source (post, user, term) by resolving each
 * constructor parameter through attributes and known-field auto-detection.
 *
 * Source-specific behaviour is supplied by the caller:
 *  - `$objectFields`:   flat array of the record's native fields (e.g. (array) $post)
 *  - `$knownFields`:    canonical system field names for the record type
 *  - `$fieldAttribute`: attribute class used to rename a property to a system field
 *  - `$metaFetcher`:    callable that returns a single meta value by key.
 *                       Contract: return `null` when the meta key does
 *                       not exist on the record; return the stored value
 *                       (including `''` and other falsey scalars) when
 *                       it does. This lets the engine distinguish a
 *                       legitimately-stored empty string from a missing
 *                       entry that should fall back to the DTO default.
 *  - `$propertyAliases`: property-name → system-field-name auto-aliases
 *                       (e.g. ['id' => 'ID'] for post/user, ['id' => 'term_id'] for term)
 *  - `$fieldTimezones`:  source-field-name → timezone map. When a
 *                       system field listed here resolves to a
 *                       non-empty string AND the target DTO param is
 *                       `DateTimeInterface`/`DateTimeImmutable`, the
 *                       engine pre-constructs a DateTimeImmutable with
 *                       that timezone. Used by Post/User sources to
 *                       tag `*_gmt` fields as UTC and local fields as
 *                       site timezone.
 *
 * Kept WP-independent so every record-source engine stays unit-testable.
 *
 * @internal Not part of the public API.
 */
final class AttributeDrivenHydrator
{
    /**
     * @template T of DataObject
     * @param class-string<T>         $dtoClass
     * @param array<string, mixed>    $objectFields
     * @param list<string>            $knownFields
     * @param class-string            $fieldAttribute
     * @param callable(string): mixed $metaFetcher
     * @param array<string, string>   $propertyAliases
     * @param array<string, string>   $fieldTimezones
     * @return T
     */
    public static function hydrate(
        string $dtoClass,
        array $objectFields,
        array $knownFields,
        string $fieldAttribute,
        callable $metaFetcher,
        array $propertyAliases = [],
        array $fieldTimezones = [],
    ): DataObject {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $dtoClass::fromArray([]);
        }

        $data = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            $metaAttr = $parameter->getAttributes(MetaKey::class)[0] ?? null;
            if ($metaAttr !== null) {
                /** @var MetaKey $instance */
                $instance = $metaAttr->newInstance();
                $value = $metaFetcher($instance->key);

                if ($value === null) {
                    if ($parameter->isDefaultValueAvailable()) {
                        continue;
                    }

                    $type = $parameter->getType();
                    if ($type !== null && $type->allowsNull()) {
                        $data[$name] = null;
                        continue;
                    }
                }

                $isEncrypted = $instance->encrypt
                    || $parameter->getAttributes(Encrypted::class) !== [];
                if ($isEncrypted && is_string($value) && $value !== '') {
                    $value = EncryptionEngine::decrypt($value, $instance->key);
                }

                $data[$name] = $value;
                continue;
            }

            $fieldAttr = $parameter->getAttributes($fieldAttribute)[0] ?? null;
            if ($fieldAttr !== null) {
                /** @var object{name: string} $instance */
                $instance = $fieldAttr->newInstance();
                $sourceName = $instance->name;
                $data[$name] = self::maybeApplyTimezone(
                    $objectFields[$sourceName] ?? null,
                    $parameter,
                    $sourceName,
                    $fieldTimezones,
                );
                continue;
            }

            $autoField = self::autoDetect($name, $knownFields, $propertyAliases);
            if ($autoField !== null) {
                $data[$name] = self::maybeApplyTimezone(
                    $objectFields[$autoField] ?? null,
                    $parameter,
                    $autoField,
                    $fieldTimezones,
                );
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            throw new class (sprintf(
                'Property "%s" on %s has no MetaKey or %s attribute and does not match any known system field. Declare the source explicitly.',
                $name,
                $dtoClass,
                (new \ReflectionClass($fieldAttribute))->getShortName(),
            ), $dtoClass, $name) extends DataObjectException {
            };
        }

        return $dtoClass::fromArray($data);
    }

    /**
     * Pre-convert a string WP datetime into a DateTimeImmutable with the
     * supplied timezone, so the downstream TypeCoercer sees an already-
     * tagged DateTimeInterface (createFromInterface preserves the TZ).
     *
     * No-op unless: value is a non-empty string; target param type is a
     * DateTimeInterface/DateTimeImmutable; and source field has a
     * timezone entry.
     *
     * @param array<string, string> $fieldTimezones
     */
    private static function maybeApplyTimezone(
        mixed $value,
        \ReflectionParameter $parameter,
        string $sourceFieldName,
        array $fieldTimezones,
    ): mixed {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (!isset($fieldTimezones[$sourceFieldName])) {
            return $value;
        }

        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $target = $type->getName();
        if ($target !== DateTimeImmutable::class
            && $target !== DateTimeInterface::class
            && !is_subclass_of($target, DateTimeInterface::class)
        ) {
            return $value;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone($fieldTimezones[$sourceFieldName]));
        } catch (\Exception) {
            // Fall back to raw string; coercer will raise a meaningful error.
            return $value;
        }
    }

    /**
     * @param list<string>          $knownFields
     * @param array<string, string> $propertyAliases
     */
    private static function autoDetect(
        string $propertyName,
        array $knownFields,
        array $propertyAliases,
    ): ?string {
        if (isset($propertyAliases[$propertyName])) {
            return $propertyAliases[$propertyName];
        }

        if (in_array($propertyName, $knownFields, true)) {
            return $propertyName;
        }

        return null;
    }
}
