<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class TypeCoercionException extends DataObjectException
{
    public static function for(
        string $dataObjectClass,
        string $fieldName,
        string $targetType,
        mixed $value,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Cannot coerce value of type "%s" into "%s" for field "%s" on %s.',
                get_debug_type($value),
                $targetType,
                $fieldName,
                $dataObjectClass,
            ),
            $dataObjectClass,
            $fieldName,
            $previous,
        );
    }

    public static function unsupportedType(
        string $dataObjectClass,
        string $fieldName,
        string $description,
    ): self {
        return new self(
            sprintf(
                'Unsupported type "%s" for field "%s" on %s. Phase 1 supports scalars, DateTimeImmutable, BackedEnum and nested DataObject.',
                $description,
                $fieldName,
                $dataObjectClass,
            ),
            $dataObjectClass,
            $fieldName,
        );
    }
}
