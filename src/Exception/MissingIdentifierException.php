<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class MissingIdentifierException extends DataObjectException
{
    public static function forUpdate(string $dataObjectClass, string $expectedField): self
    {
        return new self(
            sprintf(
                'Cannot update from %s: no identifier found. An update requires the DTO to expose "%s" (auto-detected system field) or an equivalent mapped via the matching field attribute. For inserts, the identifier is optional.',
                $dataObjectClass,
                $expectedField,
            ),
            $dataObjectClass,
            $expectedField,
        );
    }
}
