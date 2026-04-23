<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class UserNotFoundException extends DataObjectException
{
    public static function forId(string $dataObjectClass, int $userId): self
    {
        return new self(
            sprintf('User %d not found while hydrating %s.', $userId, $dataObjectClass),
            $dataObjectClass,
        );
    }
}
