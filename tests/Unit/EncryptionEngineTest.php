<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Encryption\EncryptionEngine;
use BetterData\Exception\DecryptionFailedException;
use PHPUnit\Framework\TestCase;

final class EncryptionEngineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BETTER_DATA_ENCRYPTION_KEY')) {
            \define('BETTER_DATA_ENCRYPTION_KEY', EncryptionEngine::generateKey());
        }
    }

    public function testRoundTripPreservesPlaintext(): void
    {
        $plain = 'sk_live_' . str_repeat('abc', 40); // long-ish payload
        $envelope = EncryptionEngine::encrypt($plain);

        self::assertStringStartsWith('bd:v1:', $envelope);
        self::assertStringNotContainsString($plain, $envelope);
        self::assertSame($plain, EncryptionEngine::decrypt($envelope, 'test_key'));
    }

    public function testCiphertextIsNonDeterministic(): void
    {
        $plain = 'hello';
        $a = EncryptionEngine::encrypt($plain);
        $b = EncryptionEngine::encrypt($plain);

        self::assertNotSame($a, $b, 'each encrypt call must use a fresh IV');
        self::assertSame($plain, EncryptionEngine::decrypt($a, 'k'));
        self::assertSame($plain, EncryptionEngine::decrypt($b, 'k'));
    }

    public function testTamperedCiphertextThrows(): void
    {
        $envelope = EncryptionEngine::encrypt('hello');
        // Flip a byte in the body
        $body = substr($envelope, strlen('bd:v1:'));
        $decoded = base64_decode($body, true);
        self::assertIsString($decoded);
        $decoded[strlen($decoded) - 1] = "\x00"; // corrupt the auth tag
        $tampered = 'bd:v1:' . base64_encode($decoded);

        $this->expectException(DecryptionFailedException::class);
        EncryptionEngine::decrypt($tampered, 'test_key');
    }

    public function testLegacyPlaintextReturnedUnchanged(): void
    {
        // Value without bd:v1: prefix (legacy meta from before encryption opt-in)
        self::assertSame('legacy plaintext', EncryptionEngine::decrypt('legacy plaintext', 'test_key'));
    }

    public function testLooksEncryptedPrefixCheck(): void
    {
        self::assertTrue(EncryptionEngine::looksEncrypted('bd:v1:anything'));
        self::assertFalse(EncryptionEngine::looksEncrypted('hello'));
        self::assertFalse(EncryptionEngine::looksEncrypted(''));
    }

    public function testGenerateKeyProducesValidBase64_32Bytes(): void
    {
        $key = EncryptionEngine::generateKey();
        $decoded = base64_decode($key, true);

        self::assertIsString($decoded);
        self::assertSame(32, strlen($decoded));
    }

    public function testDecryptNonEnvelopeShortInputIsPassthrough(): void
    {
        // Without the bd:v1: prefix it's treated as legacy plaintext
        self::assertSame('x', EncryptionEngine::decrypt('x', 'k'));
    }

    public function testMalformedEnvelopeThrows(): void
    {
        $this->expectException(DecryptionFailedException::class);
        EncryptionEngine::decrypt('bd:v1:not-valid-base64!!!', 'test_key');
    }
}
