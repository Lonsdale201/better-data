<?php

declare(strict_types=1);

namespace BetterData\Internal;

use BackedEnum;
use BetterData\DataObject;
use BetterData\Exception\TypeCoercionException;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionNamedType;
use ReflectionType;

/**
 * Internal type coercion helper for DataObject hydration.
 *
 * Phase 1 scope: scalar types, DateTimeImmutable, BackedEnum, nested DataObject.
 * Union / intersection types throw for now — revisit when a real use case appears.
 *
 * @internal Not part of the public API. Subject to change without notice.
 */
final class TypeCoercer
{
    public static function coerce(
        string $dataObjectClass,
        string $fieldName,
        ?ReflectionType $type,
        mixed $value,
    ): mixed {
        if (!$type instanceof ReflectionNamedType) {
            if ($type === null) {
                return $value;
            }

            throw TypeCoercionException::unsupportedType(
                $dataObjectClass,
                $fieldName,
                (string) $type,
            );
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw TypeCoercionException::for(
                $dataObjectClass,
                $fieldName,
                $type->getName(),
                $value,
            );
        }

        $target = $type->getName();

        if ($type->isBuiltin()) {
            return self::coerceBuiltin($dataObjectClass, $fieldName, $target, $value);
        }

        return self::coerceClass($dataObjectClass, $fieldName, $target, $value);
    }

    private static function coerceBuiltin(
        string $dataObjectClass,
        string $fieldName,
        string $target,
        mixed $value,
    ): mixed {
        return match ($target) {
            'mixed' => $value,
            'string' => self::toString($dataObjectClass, $fieldName, $value),
            'int' => self::toInt($dataObjectClass, $fieldName, $value),
            'float' => self::toFloat($dataObjectClass, $fieldName, $value),
            'bool' => self::toBool($dataObjectClass, $fieldName, $value),
            'array' => self::toArray($dataObjectClass, $fieldName, $value),
            default => throw TypeCoercionException::unsupportedType(
                $dataObjectClass,
                $fieldName,
                $target,
            ),
        };
    }

    private static function coerceClass(
        string $dataObjectClass,
        string $fieldName,
        string $target,
        mixed $value,
    ): object {
        if ($value instanceof $target) {
            return $value;
        }

        if (is_subclass_of($target, DataObject::class)) {
            if (!is_array($value)) {
                throw TypeCoercionException::for($dataObjectClass, $fieldName, $target, $value);
            }

            /** @var class-string<DataObject> $target */
            return $target::fromArray($value);
        }

        if (is_subclass_of($target, BackedEnum::class)) {
            if (!is_string($value) && !is_int($value)) {
                throw TypeCoercionException::for($dataObjectClass, $fieldName, $target, $value);
            }

            /** @var class-string<BackedEnum> $target */
            return $target::from($value);
        }

        if ($target === DateTimeImmutable::class || is_subclass_of($target, DateTimeInterface::class)) {
            if ($value instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($value);
            }

            if (is_string($value) && $value !== '') {
                try {
                    return new DateTimeImmutable($value);
                } catch (\Exception $e) {
                    throw TypeCoercionException::for(
                        $dataObjectClass,
                        $fieldName,
                        $target,
                        $value,
                        $e,
                    );
                }
            }

            if (is_int($value)) {
                return (new DateTimeImmutable())->setTimestamp($value);
            }

            throw TypeCoercionException::for($dataObjectClass, $fieldName, $target, $value);
        }

        throw TypeCoercionException::unsupportedType($dataObjectClass, $fieldName, $target);
    }

    private static function toString(string $cls, string $field, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw TypeCoercionException::for($cls, $field, 'string', $value);
    }

    private static function toInt(string $cls, string $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value) && (float) (int) $value === $value) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '' && (string) (int) $value === trim($value)) {
            return (int) $value;
        }

        throw TypeCoercionException::for($cls, $field, 'int', $value);
    }

    private static function toFloat(string $cls, string $field, mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) $value;
        }

        throw TypeCoercionException::for($cls, $field, 'float', $value);
    }

    private static function toBool(string $cls, string $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return match ($normalized) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off', '' => false,
                default => throw TypeCoercionException::for($cls, $field, 'bool', $value),
            };
        }

        throw TypeCoercionException::for($cls, $field, 'bool', $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function toArray(string $cls, string $field, mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        throw TypeCoercionException::for($cls, $field, 'array', $value);
    }
}
