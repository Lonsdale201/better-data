<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Exception\MissingRequiredFieldException;
use BetterData\Hydration\HydrationContext;
use BetterData\Hydration\Hydrator;
use BetterData\Tests\Fixtures\Middleware\InjectDefaultsMiddleware;
use BetterData\Tests\Fixtures\Middleware\OrderRecordingMiddleware;
use BetterData\Tests\Fixtures\Middleware\RenameFieldsMiddleware;
use BetterData\Tests\Fixtures\Middleware\ShortCircuitMiddleware;
use BetterData\Tests\Fixtures\UserDto;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class HydratorTest extends TestCase
{
    public function testEmptyPipelineBehavesLikeFromArray(): void
    {
        $user = Hydrator::from(['email' => 'x@y.z', 'age' => 30])
            ->into(UserDto::class);

        self::assertSame('x@y.z', $user->email);
        self::assertSame(30, $user->age);
    }

    public function testRenameMiddlewareRemapsKeys(): void
    {
        $user = Hydrator::from(['user_email' => 'a@b.c', 'user_age' => 25])
            ->through(new RenameFieldsMiddleware([
                'user_email' => 'email',
                'user_age' => 'age',
            ]))
            ->into(UserDto::class);

        self::assertSame('a@b.c', $user->email);
        self::assertSame(25, $user->age);
    }

    public function testMultipleMiddlewareRunInDeclarationOrder(): void
    {
        $log = [];

        Hydrator::from(['user_email' => 'a@b.c'])
            ->through(new OrderRecordingMiddleware($log, 'outer'))
            ->through(new OrderRecordingMiddleware($log, 'inner'))
            ->through(new RenameFieldsMiddleware(['user_email' => 'email']))
            ->into(UserDto::class);

        self::assertSame(
            ['before:outer', 'before:inner', 'after:inner', 'after:outer'],
            $log,
        );
    }

    public function testInjectDefaultsAppliesMissingFields(): void
    {
        $user = Hydrator::from(['email' => 'x@y.z'])
            ->through(new InjectDefaultsMiddleware(['age' => 18, 'active' => false]))
            ->into(UserDto::class);

        self::assertSame(18, $user->age);
        self::assertFalse($user->active);
    }

    public function testShortCircuitSkipsTerminal(): void
    {
        $precomputed = UserDto::fromArray(['email' => 'short@x.com']);

        $user = Hydrator::from(['email' => 'ignored@x.com'])
            ->through(new ShortCircuitMiddleware($precomputed))
            ->into(UserDto::class);

        self::assertSame('short@x.com', $user->email);
        self::assertSame($precomputed, $user);
    }

    public function testMiddlewareCanReadAndWriteMeta(): void
    {
        /** @var array{seen?: array<string, mixed>} $capture */
        $capture = [];

        $capturer = new class ($capture) implements \BetterData\Hydration\HydrationMiddleware {
            /**
             * @param array<string, mixed> $capture
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private array &$capture)
            {
            }

            public function process(HydrationContext $context, callable $next): \BetterData\DataObject
            {
                $this->capture['seen'] = $context->meta;

                return $next($context->withMeta(['mutated' => true]));
            }
        };

        Hydrator::from(['email' => 'x@y.z'], ['source' => 'webhook'])
            ->through($capturer)
            ->into(UserDto::class);

        self::assertSame(['source' => 'webhook'], $capture['seen'] ?? null);
    }

    public function testNonDataObjectClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subclass of');

        /** @phpstan-ignore-next-line argument.type — intentional misuse under test */
        Hydrator::from([])->into(stdClass::class);
    }

    public function testUnderlyingCoercionExceptionsPropagate(): void
    {
        $this->expectException(MissingRequiredFieldException::class);

        Hydrator::from([])
            ->through(new InjectDefaultsMiddleware(['age' => 10]))
            ->into(UserDto::class);
    }
}
