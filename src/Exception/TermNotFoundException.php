<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class TermNotFoundException extends DataObjectException
{
    public static function forId(string $dataObjectClass, int $termId): self
    {
        return new self(
            sprintf('Term %d not found while hydrating %s.', $termId, $dataObjectClass),
            $dataObjectClass,
        );
    }
}
