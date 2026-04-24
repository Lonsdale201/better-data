<?php

declare(strict_types=1);

namespace BetterData\Exception;

use LogicException;

/**
 * Thrown when PHP's built-in `serialize()` / `unserialize()` is invoked
 * on a `BetterData\Secret`. We block the path intentionally — silently
 * redacting the value would make `serialize(...)` lossy (losing data
 * caches expect to keep), and silently round-tripping the plaintext
 * would defeat the entire point of the type.
 *
 * Resolution: call `Secret::reveal()` explicitly to get the raw string
 * and persist that through a channel you've audited (encrypted cache,
 * secrets manager, etc.), or avoid serializing DTOs that carry a
 * Secret altogether.
 */
final class SecretSerializationException extends LogicException
{
    public static function forSerialize(): self
    {
        return new self(
            'BetterData\\Secret instances cannot be serialized via PHP serialize(). '
            . 'Serialization would either leak the raw value or silently drop it. '
            . 'Call Secret::reveal() explicitly to obtain the raw string and persist '
            . 'it through an audited channel (encrypted cache, secrets manager) instead.',
        );
    }

    public static function forUnserialize(): self
    {
        return new self(
            'BetterData\\Secret instances cannot be unserialized via PHP unserialize(). '
            . 'Reconstruct the Secret explicitly with new Secret($rawValue) after obtaining '
            . 'the value through an audited channel.',
        );
    }
}
