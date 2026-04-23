<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Exception\MissingRequiredFieldException;
use BetterData\Tests\Fixtures\AddressDto;
use BetterData\Tests\Fixtures\ProfileDto;
use BetterData\Tests\Fixtures\UserDto;
use BetterData\Tests\Fixtures\UserRole;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

final class DataObjectTest extends TestCase
{
    public function testFromArrayBuildsFullDto(): void
    {
        $user = UserDto::fromArray([
            'email' => 'jane@example.com',
            'name' => 'Jane',
            'age' => 34,
            'active' => true,
        ]);

        self::assertSame('jane@example.com', $user->email);
        self::assertSame('Jane', $user->name);
        self::assertSame(34, $user->age);
        self::assertTrue($user->active);
    }

    public function testFromArrayUsesDefaultsForMissingOptionalFields(): void
    {
        $user = UserDto::fromArray(['email' => 'x@y.z']);

        self::assertNull($user->name);
        self::assertSame(0, $user->age);
        self::assertTrue($user->active);
    }

    public function testFromArrayThrowsForMissingRequired(): void
    {
        $this->expectException(MissingRequiredFieldException::class);
        $this->expectExceptionMessage('Missing required field "email"');

        UserDto::fromArray([]);
    }

    public function testFromArrayIgnoresExtraKeys(): void
    {
        $user = UserDto::fromArray([
            'email' => 'a@b.c',
            'unknown_field' => 'ignored',
        ]);

        self::assertSame('a@b.c', $user->email);
    }

    public function testToArrayRoundTrip(): void
    {
        $source = [
            'email' => 'j@example.com',
            'name' => 'Jane',
            'age' => 30,
            'active' => false,
        ];

        $user = UserDto::fromArray($source);

        self::assertSame($source, $user->toArray());
    }

    public function testWithReplacesFieldsAndReturnsNewInstance(): void
    {
        $user = UserDto::fromArray(['email' => 'old@x.com', 'age' => 20]);
        $updated = $user->with(['age' => 21]);

        self::assertNotSame($user, $updated);
        self::assertSame('old@x.com', $updated->email);
        self::assertSame(21, $updated->age);
        self::assertSame(20, $user->age, 'Original instance must be unchanged');
    }

    public function testNestedDtoHydrationAndSerialization(): void
    {
        $profile = ProfileDto::fromArray([
            'username' => 'jane',
            'role' => 'admin',
            'address' => ['city' => 'Budapest', 'country' => 'HU', 'zip' => '1011'],
            'joinedAt' => '2026-01-15T10:00:00+00:00',
            'balance' => '42.5',
        ]);

        self::assertSame(UserRole::Admin, $profile->role);
        self::assertInstanceOf(AddressDto::class, $profile->address);
        self::assertSame('Budapest', $profile->address->city);
        self::assertInstanceOf(DateTimeImmutable::class, $profile->joinedAt);
        self::assertSame(42.5, $profile->balance);

        $out = $profile->toArray();
        self::assertSame('admin', $out['role']);
        self::assertSame(
            ['city' => 'Budapest', 'country' => 'HU', 'zip' => '1011'],
            $out['address'],
        );
        self::assertSame(
            '2026-01-15T10:00:00+00:00',
            $out['joinedAt'],
        );
    }

    public function testWithRoundTripsNestedDto(): void
    {
        $profile = ProfileDto::fromArray([
            'username' => 'jane',
            'role' => 'editor',
            'address' => ['city' => 'Budapest', 'country' => 'HU'],
            'joinedAt' => '2026-01-15T10:00:00+00:00',
        ]);

        $updated = $profile->with([
            'address' => ['city' => 'Vienna', 'country' => 'AT'],
        ]);

        self::assertSame('Vienna', $updated->address->city);
        self::assertSame('jane', $updated->username);
        self::assertSame(UserRole::Editor, $updated->role);
    }

    public function testDateTimeImmutableSerializesAsAtom(): void
    {
        $profile = ProfileDto::fromArray([
            'username' => 'x',
            'role' => 'subscriber',
            'address' => ['city' => 'X', 'country' => 'Y'],
            'joinedAt' => new DateTimeImmutable('2026-04-01T12:00:00+00:00'),
        ]);

        self::assertSame(
            '2026-04-01T12:00:00+00:00',
            $profile->toArray()['joinedAt'],
        );
        self::assertInstanceOf(DateTimeInterface::class, $profile->joinedAt);
    }
}
