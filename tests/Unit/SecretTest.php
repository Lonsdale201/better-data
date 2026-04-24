<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Exception\SecretSerializationException;
use BetterData\Presenter\Presenter;
use BetterData\Secret;
use BetterData\Tests\Fixtures\SecretDto;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
    public function testRevealReturnsRawValue(): void
    {
        $s = new Secret('sk_live_xyz');
        self::assertSame('sk_live_xyz', $s->reveal());
    }

    public function testCastToStringRedacts(): void
    {
        self::assertSame('***', (string) new Secret('sk_live_xyz'));
    }

    public function testJsonEncodeRedacts(): void
    {
        $json = json_encode(new Secret('sk_live_xyz'));
        self::assertSame('"***"', $json);
    }

    public function testJsonEncodeInArrayRedacts(): void
    {
        $json = json_encode(['key' => new Secret('sk_live_xyz')]);
        self::assertSame('{"key":"***"}', $json);
    }

    public function testVarDumpOutputRedacted(): void
    {
        ob_start();
        var_dump(new Secret('sk_live_xyz'));
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('sk_live_xyz', $dump);
        self::assertStringContainsString('***', $dump);
    }

    public function testPrintRRedacted(): void
    {
        $out = print_r(new Secret('sk_live_xyz'), true);

        self::assertStringNotContainsString('sk_live_xyz', $out);
        self::assertStringContainsString('***', $out);
    }

    public function testSerializeThrows(): void
    {
        $this->expectException(SecretSerializationException::class);
        serialize(new Secret('sk_live_xyz'));
    }

    public function testEqualsAcceptsSecretAndString(): void
    {
        $s = new Secret('sk_live_xyz');

        self::assertTrue($s->equals(new Secret('sk_live_xyz')));
        self::assertTrue($s->equals('sk_live_xyz'));
        self::assertFalse($s->equals('sk_live_abc'));
        self::assertFalse($s->equals(new Secret('sk_live_abc')));
    }

    public function testDtoHydratesStringIntoSecret(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => 'sk_live_xyz',
            'refreshToken' => 'rt_abc',
        ]);

        self::assertInstanceOf(Secret::class, $dto->clientSecret);
        self::assertSame('sk_live_xyz', $dto->clientSecret->reveal());
        self::assertInstanceOf(Secret::class, $dto->refreshToken);
        self::assertSame('rt_abc', $dto->refreshToken->reveal());
    }

    public function testDtoAcceptsExistingSecretInstance(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => new Secret('sk_live_xyz'),
        ]);

        self::assertSame('sk_live_xyz', $dto->clientSecret->reveal());
    }

    public function testDtoToArrayRedactsSecrets(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => 'sk_live_xyz',
            'refreshToken' => 'rt_abc',
        ]);

        $out = $dto->toArray();

        self::assertSame('app-1', $out['clientId']);
        self::assertSame('***', $out['clientSecret']);
        self::assertSame('***', $out['refreshToken']);
    }

    public function testWithPreservesSecretAcrossRoundTrip(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => 'sk_live_xyz',
        ]);

        $next = $dto->with(['clientId' => 'app-2']);

        self::assertSame('app-2', $next->clientId);
        // The secret must survive with() — toArray()'s '***' must not
        // leak into the re-hydration path.
        self::assertSame('sk_live_xyz', $next->clientSecret->reveal());
    }

    public function testPresenterExcludesSecretTypedByDefault(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => 'sk_live_xyz',
        ]);

        $out = Presenter::for($dto)->toArray();

        self::assertArrayHasKey('clientId', $out);
        self::assertArrayNotHasKey('clientSecret', $out);
        self::assertArrayNotHasKey('refreshToken', $out);
    }

    public function testPresenterIncludeSensitiveStillRedactsSecretValue(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => 'sk_live_xyz',
        ]);

        $out = Presenter::for($dto)->includeSensitive(['clientSecret'])->toArray();

        self::assertArrayHasKey('clientSecret', $out);
        self::assertSame('***', $out['clientSecret']);
    }

    public function testPresenterComputeCanRevealSecretExplicitly(): void
    {
        $dto = SecretDto::fromArray([
            'clientId' => 'app-1',
            'clientSecret' => 'sk_live_xyz',
        ]);

        $out = Presenter::for($dto)
            ->compute(
                'lastFour',
                static fn (SecretDto $d): string => substr($d->clientSecret->reveal(), -4),
            )
            ->only(['clientId', 'lastFour'])
            ->toArray();

        self::assertSame(['clientId' => 'app-1', 'lastFour' => '_xyz'], $out);
    }
}
