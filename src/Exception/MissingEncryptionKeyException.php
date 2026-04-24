<?php

declare(strict_types=1);

namespace BetterData\Exception;

use RuntimeException;

/**
 * Thrown when a DTO property declares `#[MetaKey(..., encrypt: true)]`
 * but the `BETTER_DATA_ENCRYPTION_KEY` constant / filter is missing or
 * malformed.
 *
 * Deliberately loud rather than silently storing plaintext, because
 * a missing key is a configuration error that must be fixed before
 * the application handles real secrets.
 */
final class MissingEncryptionKeyException extends RuntimeException
{
    public static function notDefined(): self
    {
        return new self(
            'BetterData at-rest encryption requires BETTER_DATA_ENCRYPTION_KEY. '
            . 'Define it in wp-config.php before any secret-carrying DTO is hydrated/persisted. '
            . 'Generate a fresh key with: php -r "echo base64_encode(random_bytes(32)).PHP_EOL;" '
            . 'and put the result into define(\'BETTER_DATA_ENCRYPTION_KEY\', \'<base64>\');. '
            . 'The key must decode to exactly 32 raw bytes (AES-256).',
        );
    }

    public static function invalidLength(int $actualBytes): self
    {
        return new self(sprintf(
            'BETTER_DATA_ENCRYPTION_KEY decoded to %d bytes; AES-256-GCM requires exactly 32. '
            . 'Regenerate: php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"',
            $actualBytes,
        ));
    }

    public static function decodingFailed(): self
    {
        return new self(
            'BETTER_DATA_ENCRYPTION_KEY is not valid base64. '
            . 'Expected a base64-encoded 32-byte key.',
        );
    }
}
