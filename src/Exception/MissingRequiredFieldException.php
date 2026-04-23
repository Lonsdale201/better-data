<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class MissingRequiredFieldException extends DataObjectException
{
    public static function for(string $dataObjectClass, string $fieldName): self
    {
        return new self(
            sprintf(
                'Missing required field "%s" when hydrating %s.',
                $fieldName,
                $dataObjectClass,
            ),
            $dataObjectClass,
            $fieldName,
        );
    }
}
