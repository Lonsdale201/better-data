<?php

declare(strict_types=1);

namespace BetterData\Exception;

/**
 * Thrown when a strict `only` / whitelist API receives a field name
 * that does not exist on the target DataObject. Helps catch typos
 * early instead of silently dropping the unknown field.
 */
final class UnknownFieldException extends DataObjectException
{
    /**
     * @param list<string> $unknown
     * @param list<string> $available
     */
    public static function forFields(string $dtoClass, array $unknown, array $available): self
    {
        return new self(sprintf(
            'Unknown field(s) [%s] passed to a strict whitelist on %s. Available: [%s].',
            implode(', ', $unknown),
            $dtoClass,
            implode(', ', $available),
        ), $dtoClass);
    }
}
