<?php

declare(strict_types=1);

namespace BetterData\Encryption;

use BetterData\Exception\DecryptionFailedException;
use BetterData\Exception\MissingEncryptionKeyException;

/**
 * AES-256-GCM envelope for `#[MetaKey(..., encrypt: true)]` values.
 *
 * ## Envelope format
 *
 *     bd:v1:base64(iv || ciphertext || tag)
 *
 *  - `bd:` namespace prefix — unambiguously identifies our ciphertext
 *    if the consumer inspects raw wp_postmeta.
 *  - `v1` version — bumps when we change the envelope so old
 *    ciphertexts can still be read through the `decrypt` path.
 *  - `base64` wraps the binary body so the value stays safe to
 *    pass through WP APIs that expect text.
 *  - Body = 12-byte IV (GCM standard) || ciphertext || 16-byte auth tag.
 *
 * ## Key source
 *
 *  - `BETTER_DATA_ENCRYPTION_KEY` — PHP constant (preferred).
 *  - `better_data_encryption_key` WP filter — fallback.
 *  - Both must be base64 of exactly 32 raw bytes (AES-256).
 *
 * Rotation: define `BETTER_DATA_ENCRYPTION_KEY_PREVIOUS` with the old
 * key while rolling the primary. Decrypt tries primary first, then
 * previous on failure; writes always use the primary.
 *
 * ## Failure modes
 *
 * Missing / malformed key → `MissingEncryptionKeyException`
 *   (thrown at first encrypt/decrypt, not at library boot, so a
 *    plugin with no encrypted fields is never affected).
 *
 * Decrypt failure → `DecryptionFailedException` (GCM auth tag
 *   mismatch, rotation without previous key, tampered value).
 *
 * @internal API may still shift before v0.1.0 tag.
 */
final class EncryptionEngine
{
    private const ENVELOPE_PREFIX = 'bd:v1:';
    private const KEY_BYTES = 32; // AES-256
    private const IV_BYTES = 12;  // GCM standard
    private const TAG_BYTES = 16;

    private const CONST_PRIMARY = 'BETTER_DATA_ENCRYPTION_KEY';
    private const CONST_PREVIOUS = 'BETTER_DATA_ENCRYPTION_KEY_PREVIOUS';
    private const FILTER_PRIMARY = 'better_data_encryption_key';

    /**
     * Encrypt a plaintext string, returning a `bd:v1:...` envelope.
     * Called from the write path when `#[MetaKey(encrypt: true)]`.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::requirePrimaryKey();
        $iv = \random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = \openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException(
                'openssl_encrypt failed — check that the openssl extension is loaded and aes-256-gcm is available.',
            );
        }

        return self::ENVELOPE_PREFIX . \base64_encode($iv . $ciphertext . $tag);
    }

    /**
     * Decrypt a `bd:v1:...` envelope back into plaintext. Falls through
     * to the previous key if the primary fails, to support key rotation.
     *
     * A non-envelope string (no `bd:v1:` prefix) is returned unchanged
     * — this handles legacy plaintext meta written before a consumer
     * opted into encryption. Plaintext writes are never created by this
     * library when `encrypt: true`, so a prefix-less value can only
     * come from legacy data.
     *
     * @param string $value    the raw stored value
     * @param string $metaKey  included in the exception for debuggability
     */
    public static function decrypt(string $value, string $metaKey): string
    {
        if (!self::looksEncrypted($value)) {
            return $value; // legacy plaintext pass-through
        }

        $body = \substr($value, strlen(self::ENVELOPE_PREFIX));
        $raw = \base64_decode($body, true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES + 1) {
            throw DecryptionFailedException::forKey($metaKey);
        }

        $iv = \substr($raw, 0, self::IV_BYTES);
        $tag = \substr($raw, -self::TAG_BYTES);
        $ciphertext = \substr($raw, self::IV_BYTES, -self::TAG_BYTES);

        foreach (self::keysInDecryptOrder() as $key) {
            $plain = @\openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
            );
            if (is_string($plain)) {
                return $plain;
            }
        }

        throw DecryptionFailedException::forKey($metaKey);
    }

    /**
     * Cheap check — does the value start with our envelope prefix?
     * Used by the engine before touching the key material.
     */
    public static function looksEncrypted(string $value): bool
    {
        return \str_starts_with($value, self::ENVELOPE_PREFIX);
    }

    /**
     * Helper for consumers: generate a fresh 32-byte key, base64-encoded,
     * ready to paste into a `define(...)` call.
     */
    public static function generateKey(): string
    {
        return \base64_encode(\random_bytes(self::KEY_BYTES));
    }

    private static function requirePrimaryKey(): string
    {
        $raw = self::readConstant(self::CONST_PRIMARY)
            ?? self::readFilter(self::FILTER_PRIMARY);

        if ($raw === null || $raw === '') {
            throw MissingEncryptionKeyException::notDefined();
        }

        return self::decodeKeyOrThrow($raw);
    }

    /**
     * @return list<string>
     */
    private static function keysInDecryptOrder(): array
    {
        $keys = [];
        $primary = self::readConstant(self::CONST_PRIMARY)
            ?? self::readFilter(self::FILTER_PRIMARY);
        if (is_string($primary) && $primary !== '') {
            $keys[] = self::decodeKeyOrThrow($primary);
        }

        $previous = self::readConstant(self::CONST_PREVIOUS);
        if (is_string($previous) && $previous !== '') {
            try {
                $keys[] = self::decodeKeyOrThrow($previous);
            } catch (\Throwable) {
                // Malformed previous key shouldn't break primary decrypt —
                // just skip it. Primary is the source of truth.
            }
        }

        if ($keys === []) {
            throw MissingEncryptionKeyException::notDefined();
        }

        return $keys;
    }

    private static function readConstant(string $name): ?string
    {
        if (!\defined($name)) {
            return null;
        }
        $value = \constant($name);

        return is_string($value) ? $value : null;
    }

    private static function readFilter(string $name): ?string
    {
        if (!\function_exists('apply_filters')) {
            return null;
        }
        $value = \apply_filters($name, null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function decodeKeyOrThrow(string $raw): string
    {
        $decoded = \base64_decode($raw, true);
        if ($decoded === false) {
            throw MissingEncryptionKeyException::decodingFailed();
        }
        if (strlen($decoded) !== self::KEY_BYTES) {
            throw MissingEncryptionKeyException::invalidLength(strlen($decoded));
        }

        return $decoded;
    }
}
