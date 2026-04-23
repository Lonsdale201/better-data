<?php

declare(strict_types=1);

namespace BetterData\Internal;

use BetterData\Attribute\Column;
use BetterData\DataObject;
use ReflectionClass;

/**
 * Resolves property-to-column aliases declared via #[Column] on a DTO.
 *
 * Builds a small map of "column name in row" → "field name the DTO
 * expects", then rewrites the input row array accordingly so that
 * `fromArray()` can consume it under the PHP property names.
 *
 * @internal Not part of the public API.
 */
final class ColumnMapper
{
    /**
     * Rewrites a raw row array by translating `#[Column]`-aliased columns
     * to their corresponding property names.
     *
     * @param class-string<DataObject> $dtoClass
     * @param array<string, mixed>     $row
     * @return array<string, mixed>
     */
    public static function applyAliases(string $dtoClass, array $row): array
    {
        $aliases = self::buildAliasMap($dtoClass);
        if ($aliases === []) {
            return $row;
        }

        foreach ($aliases as $column => $property) {
            if ($column === $property) {
                continue;
            }

            if (array_key_exists($column, $row)) {
                $row[$property] = $row[$column];
                unset($row[$column]);
            }
        }

        return $row;
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @return array<string, string> column name => property name
     */
    private static function buildAliasMap(string $dtoClass): array
    {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $map = [];
        foreach ($constructor->getParameters() as $parameter) {
            $attr = $parameter->getAttributes(Column::class)[0] ?? null;
            if ($attr === null) {
                continue;
            }

            /** @var Column $instance */
            $instance = $attr->newInstance();
            $map[$instance->name] = $parameter->getName();
        }

        return $map;
    }
}
