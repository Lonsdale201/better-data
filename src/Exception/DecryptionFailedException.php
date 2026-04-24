<?php

declare(strict_types=1);

namespace BetterData\Exception;

use RuntimeException;

/**
 * Thrown when a stored ciphertext fails to decrypt. Reasons include:
 *  - Ciphertext tampered with (GCM auth tag mismatch).
 *  - Encryption key changed without rotation (previous key missing).
 *  - Malformed / truncated ciphertext.
 *  - Ciphertext from a different envelope version (`bd:v2:...`) that
 *    this release of the library doesn't know how to parse.
 *
 * The message intentionally stays generic — detailed failure reasons
 * can leak oracle information in some threat models.
 */
final class DecryptionFailedException extends RuntimeException
{
    public static function forKey(string $metaKey): self
    {
        return new self(sprintf(
            'Failed to decrypt stored value for meta key "%s". '
            . 'Possible causes: tampered ciphertext, missing previous key for rotation, '
            . 'or envelope version mismatch. See BetterData\\Encryption\\EncryptionEngine.',
            $metaKey,
        ));
    }
}
