<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Secret;
use BetterData\Sink\OptionSink;
use BetterData\Tests\Fixtures\SecretDto;
use PHPUnit\Framework\TestCase;

final class OptionSinkTest extends TestCase
{
    public function testToArrayUnwrapsSecretForStorage(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => 'sk_live_xyz',
            'refreshToken' => 'rt_abc',
        ]);

        $projected = OptionSink::toArray($dto);

        self::assertSame('app-1', $projected['clientId']);
        // CRITICAL: Secret must be REVEALED for option storage, not redacted.
        // DataObject::toArray would produce '***' — OptionSink must produce
        // the raw value so reads can reconstruct the original Secret.
        self::assertSame('sk_live_xyz', $projected['clientSecret']);
        self::assertSame('rt_abc', $projected['refreshToken']);
    }

    public function testToArrayVsDataObjectToArraySymmetryNote(): void
    {
        // Documents the intentional split: DataObject::toArray redacts
        // (presentation semantic), OptionSink::toArray reveals (storage
        // semantic).
        $dto = SecretDto::fromArray([
            'clientId' => 'a',
            'clientSecret' => 'RAW',
        ]);

        self::assertSame('***', $dto->toArray()['clientSecret']);
        self::assertSame('RAW', OptionSink::toArray($dto)['clientSecret']);
    }

    public function testNullableSecretAbsentStoredAsNull(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'a',
            'clientSecret' => 'x',
        ]);

        $projected = OptionSink::toArray($dto);
        self::assertNull($projected['refreshToken']);
    }
}
