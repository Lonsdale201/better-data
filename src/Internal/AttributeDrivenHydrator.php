<?php

declare(strict_types=1);

namespace BetterData\Internal;

use BetterData\Attribute\MetaKey;
use BetterData\DataObject;
use BetterData\Exception\DataObjectException;
use ReflectionClass;

/**
 * Generic engine that hydrates a DataObject from any WordPress-style
 * record-plus-metadata source (post, user, term) by resolving each
 * constructor parameter through attributes and known-field auto-detection.
 *
 * Source-specific behaviour is supplied by the caller:
 *  - `$objectFields`:   flat array of the record's native fields (e.g. (array) $post)
 *  - `$knownFields`:    canonical system field names for the record type
 *  - `$fieldAttribute`: attribute class used to rename a property to a system field
 *  - `$metaFetcher`:    callable that returns a single meta value by key
 *  - `$propertyAliases`: property-name → system-field-name auto-aliases
 *                       (e.g. ['id' => 'ID'] for post/user, ['id' => 'term_id'] for term)
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
     * @return T
     */
    public static function hydrate(
        string $dtoClass,
        array $objectFields,
        array $knownFields,
        string $fieldAttribute,
        callable $metaFetcher,
        array $propertyAliases = [],
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

                if ($value === '' || $value === false) {
                    if ($parameter->isDefaultValueAvailable()) {
                        continue;
                    }

                    $type = $parameter->getType();
                    if ($type !== null && $type->allowsNull()) {
                        $data[$name] = null;
                        continue;
                    }
                }

                $data[$name] = $value;
                continue;
            }

            $fieldAttr = $parameter->getAttributes($fieldAttribute)[0] ?? null;
            if ($fieldAttr !== null) {
                /** @var object{name: string} $instance */
                $instance = $fieldAttr->newInstance();
                $data[$name] = $objectFields[$instance->name] ?? null;
                continue;
            }

            $autoField = self::autoDetect($name, $knownFields, $propertyAliases);
            if ($autoField !== null) {
                $data[$name] = $objectFields[$autoField] ?? null;
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
