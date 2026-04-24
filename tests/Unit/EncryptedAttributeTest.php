<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Encryption\EncryptionEngine;
use BetterData\Secret;
use BetterData\Sink\OptionSink;
use BetterData\Tests\Fixtures\OptionEncryptedDto;
use PHPUnit\Framework\TestCase;

final class EncryptedAttributeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BETTER_DATA_ENCRYPTION_KEY')) {
            \define('BETTER_DATA_ENCRYPTION_KEY', EncryptionEngine::generateKey());
        }
    }

    public function testOptionSinkEncryptsAnnotatedFieldOnWrite(): void
    {
        $dto = OptionEncryptedDto::fromArray([
            'shopName' => 'Acme',
            'apiKey' => 'sk_live_abc',
            'plainSecretString' => 'also-encrypted',
        ]);

        $projection = OptionSink::toArray($dto);

        self::assertSame('Acme', $projection['shopName']); // not encrypted
        self::assertIsString($projection['apiKey']);
        self::assertTrue(
            EncryptionEngine::looksEncrypted($projection['apiKey']),
            'apiKey must be stored as bd:v1: envelope',
        );
        self::assertStringNotContainsString('sk_live_abc', $projection['apiKey']);
        self::assertTrue(EncryptionEngine::looksEncrypted($projection['plainSecretString']));
    }

    public function testRoundTripThroughOptionProjection(): void
    {
        $dto = OptionEncryptedDto::fromArray([
            'shopName' => 'Acme',
            'apiKey' => 'sk_live_abc',
        ]);

        $stored = OptionSink::toArray($dto);
        // Simulate read from wp_options and re-hydrate
        $reloaded = OptionEncryptedDto::fromArray($stored);

        self::assertInstanceOf(Secret::class, $reloaded->apiKey);
        self::assertSame('sk_live_abc', $reloaded->apiKey->reveal());
        self::assertNull($reloaded->plainSecretString);
    }

    public function testLegacyPlaintextInOptionPassesThroughOnRead(): void
    {
        // Option value stored BEFORE encryption opt-in — no bd:v1: prefix.
        $legacy = [
            'shopName' => 'Acme',
            'apiKey' => 'sk_live_legacy',
        ];

        $dto = OptionEncryptedDto::fromArray($legacy);

        self::assertSame('sk_live_legacy', $dto->apiKey->reveal());
    }
}
