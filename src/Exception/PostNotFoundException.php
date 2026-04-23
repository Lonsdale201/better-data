<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class PostNotFoundException extends DataObjectException
{
    public static function forId(string $dataObjectClass, int $postId): self
    {
        return new self(
            sprintf('Post %d not found while hydrating %s.', $postId, $dataObjectClass),
            $dataObjectClass,
        );
    }
}
