<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Exception\TypeCoercionException;
use BetterData\Tests\Fixtures\UserDto;
use PHPUnit\Framework\TestCase;

final class TypeCoercionTest extends TestCase
{
    public function testStringToIntCoercion(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'age' => '42']);
        self::assertSame(42, $user->age);
    }

    public function testIntToStringCoercion(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 12345]);
        self::assertSame('12345', $user->name);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function boolCoercionProvider(): iterable
    {
        yield 'string "1"' => ['1', true];
        yield 'string "0"' => ['0', false];
        yield 'string "true"' => ['true', true];
        yield 'string "false"' => ['false', false];
        yield 'string "yes"' => ['yes', true];
        yield 'string "no"' => ['no', false];
        yield 'string "on"' => ['on', true];
        yield 'string "off"' => ['off', false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'bool true' => [true, true];
        yield 'empty string' => ['', false];
    }

    /**
     * @dataProvider boolCoercionProvider
     */
    public function testBoolCoercion(mixed $input, bool $expected): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'active' => $input]);
        self::assertSame($expected, $user->active);
    }

    public function testInvalidIntStringThrows(): void
    {
        $this->expectException(TypeCoercionException::class);
        $this->expectExceptionMessage('"int"');

        UserDto::fromArray(['email' => 'a@b.c', 'age' => 'not-a-number']);
    }

    public function testInvalidBoolStringThrows(): void
    {
        $this->expectException(TypeCoercionException::class);

        UserDto::fromArray(['email' => 'a@b.c', 'active' => 'maybe']);
    }

    public function testExplicitNullForNullableIsPreserved(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => null]);
        self::assertNull($user->name);
    }

    public function testNullForNonNullableThrows(): void
    {
        $this->expectException(TypeCoercionException::class);

        UserDto::fromArray(['email' => null]);
    }
}
