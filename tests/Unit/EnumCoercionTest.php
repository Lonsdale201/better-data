<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Exception\TypeCoercionException;
use BetterData\Tests\Fixtures\ProfileDto;
use BetterData\Tests\Fixtures\UserRole;
use PHPUnit\Framework\TestCase;

final class EnumCoercionTest extends TestCase
{
    public function testValidEnumStringCoerces(): void
    {
        $profile = ProfileDto::fromArray([
            'username' => 'x',
            'role' => 'subscriber',
            'address' => ['city' => 'A', 'country' => 'B'],
            'joinedAt' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame(UserRole::Subscriber, $profile->role);
    }

    public function testInvalidEnumValueThrowsTypeCoercionException(): void
    {
        try {
            ProfileDto::fromArray([
                'username' => 'x',
                'role' => 'ghost',
                'address' => ['city' => 'A', 'country' => 'B'],
                'joinedAt' => '2026-01-01T00:00:00+00:00',
            ]);
            self::fail('Expected TypeCoercionException');
        } catch (TypeCoercionException $e) {
            self::assertSame('role', $e->getFieldName());
            self::assertInstanceOf(\ValueError::class, $e->getPrevious());
        }
    }

    public function testInvalidEnumInputTypeThrowsTypeCoercionException(): void
    {
        $this->expectException(TypeCoercionException::class);

        ProfileDto::fromArray([
            'username' => 'x',
            'role' => ['nested' => 'array'],
            'address' => ['city' => 'A', 'country' => 'B'],
            'joinedAt' => '2026-01-01T00:00:00+00:00',
        ]);
    }
}
