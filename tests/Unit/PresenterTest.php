<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Presenter\PresentationContext;
use BetterData\Presenter\Presenter;
use BetterData\Tests\Fixtures\AddressDto;
use BetterData\Tests\Fixtures\ProfileDto;
use BetterData\Tests\Fixtures\UserDto;
use BetterData\Tests\Fixtures\UserRole;
use PHPUnit\Framework\TestCase;

final class PresenterTest extends TestCase
{
    public function testEmptyConfigReturnsDtoShape(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A', 'age' => 30]);

        $out = Presenter::for($user)->toArray();

        self::assertSame([
            'email' => 'a@b.c',
            'name' => 'A',
            'age' => 30,
            'active' => true,
        ], $out);
    }

    public function testOnlyWhitelistRestrictsOutput(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']);

        $out = Presenter::for($user)->only(['email', 'name'])->toArray();

        self::assertSame(['email' => 'a@b.c', 'name' => 'A'], $out);
    }

    public function testHideRemovesField(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']);

        $out = Presenter::for($user)->hide('email')->toArray();

        self::assertArrayNotHasKey('email', $out);
    }

    public function testHideWithPredicate(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']);

        $outRest = Presenter::for($user)
            ->context(new PresentationContext('rest'))
            ->hide('email', fn (PresentationContext $ctx) => $ctx->name === 'rest')
            ->toArray();

        $outAdmin = Presenter::for($user)
            ->context(new PresentationContext('admin'))
            ->hide('email', fn (PresentationContext $ctx) => $ctx->name === 'rest')
            ->toArray();

        self::assertArrayNotHasKey('email', $outRest);
        self::assertArrayHasKey('email', $outAdmin);
    }

    public function testRenameSingleAndBulk(): void
    {
        // Semantic: only()/hide() operate on INPUT keys (DTO props or computed names).
        // rename() is a pure output-key remap applied at the end.
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']);

        $single = Presenter::for($user)->rename('email', 'emailAddress')->only(['email'])->toArray();
        self::assertSame(['emailAddress' => 'a@b.c'], $single);

        $bulk = Presenter::for($user)
            ->rename(['email' => 'e', 'name' => 'n'])
            ->only(['email', 'name'])
            ->toArray();
        self::assertSame(['e' => 'a@b.c', 'n' => 'A'], $bulk);
    }

    public function testRenameRequiresTargetForStringSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Presenter::for(UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']))
            ->rename('email');
    }

    public function testComputeAddsOrOverridesField(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Alice']);

        $out = Presenter::for($user)
            ->compute('greeting', fn (UserDto $dto) => 'Hello, ' . $dto->name)
            ->compute('name', fn (UserDto $dto) => strtoupper($dto->name))
            ->toArray();

        self::assertSame('Hello, Alice', $out['greeting']);
        self::assertSame('ALICE', $out['name']);
    }

    public function testComputeIsLazyWhenFieldExcluded(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']);
        $called = false;

        $out = Presenter::for($user)
            ->compute('heavy', function () use (&$called): string {
                $called = true;

                return 'x';
            })
            ->only(['email'])
            ->toArray();

        self::assertFalse($called, 'compute closure must not run when field is outside `only`');
        self::assertArrayNotHasKey('heavy', $out);
    }

    public function testComputeIsLazyWhenFieldHidden(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']);
        $called = false;

        Presenter::for($user)
            ->compute('heavy', function () use (&$called): string {
                $called = true;

                return 'x';
            })
            ->hide('heavy')
            ->toArray();

        self::assertFalse($called);
    }

    public function testNestedDtoIsRecursivelyPresented(): void
    {
        $profile = ProfileDto::fromArray([
            'username' => 'jane',
            'role' => 'admin',
            'address' => ['city' => 'Budapest', 'country' => 'HU', 'zip' => '1011'],
            'joinedAt' => '2026-01-15T10:00:00+00:00',
        ]);

        $out = Presenter::for($profile)->toArray();

        self::assertSame(
            ['city' => 'Budapest', 'country' => 'HU', 'zip' => '1011'],
            $out['address'],
        );
    }

    public function testPresetOverridesNestedRendering(): void
    {
        $profile = ProfileDto::fromArray([
            'username' => 'jane',
            'role' => 'admin',
            'address' => ['city' => 'Budapest', 'country' => 'HU', 'zip' => '1011'],
            'joinedAt' => '2026-01-15T10:00:00+00:00',
        ]);

        $out = Presenter::for($profile)
            ->preset('address', fn (AddressDto $a) => $a->city . ', ' . $a->country)
            ->toArray();

        self::assertSame('Budapest, HU', $out['address']);
    }

    public function testEnumSerializedAsScalar(): void
    {
        $profile = ProfileDto::fromArray([
            'username' => 'x',
            'role' => 'editor',
            'address' => ['city' => 'A', 'country' => 'B'],
            'joinedAt' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame('editor', Presenter::for($profile)->toArray()['role']);
        self::assertSame(UserRole::Editor, $profile->role, 'Original DTO unaffected');
    }

    public function testToJsonReturnsValidJson(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'A']);

        $json = Presenter::for($user)->only(['email', 'name'])->toJson();

        self::assertSame('{"email":"a@b.c","name":"A"}', $json);
    }

    public function testForCollectionAppliesSameConfigToEach(): void
    {
        $a = UserDto::fromArray(['email' => 'a@x.y', 'name' => 'A', 'age' => 20]);
        $b = UserDto::fromArray(['email' => 'b@x.y', 'name' => 'B', 'age' => 30]);

        $rows = Presenter::forCollection([$a, $b])
            ->only(['email', 'age'])
            ->rename('email', 'e')
            ->toArray();

        self::assertSame([
            ['e' => 'a@x.y', 'age' => 20],
            ['e' => 'b@x.y', 'age' => 30],
        ], $rows);
    }

    public function testSubclassConfigureRunsOnInstantiation(): void
    {
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Alice']);

        $out = TestUserPresenter::for($user)->toArray();

        self::assertSame('ALICE', $out['name']);
        self::assertArrayNotHasKey('age', $out);
    }

    public function testPresentationContextRoleAndCapabilityAreNullSafeWithoutWp(): void
    {
        $ctx = new PresentationContext('rest', userId: 42);

        // No WP runtime in unit tests → user_can / get_userdata not defined
        self::assertFalse($ctx->userCan('manage_options'));
        self::assertSame([], $ctx->userRoles());
    }

    public function testPresentationContextImmutableWithers(): void
    {
        $ctx = PresentationContext::none();
        $next = $ctx->withName('email')->withLocale('hu_HU')->withTimezone('Europe/Budapest')->withUserId(5);

        self::assertSame('none', $ctx->name);
        self::assertNull($ctx->userId);

        self::assertSame('email', $next->name);
        self::assertSame('hu_HU', $next->locale);
        self::assertSame('Europe/Budapest', $next->timezone);
        self::assertSame(5, $next->userId);
    }
}

final class TestUserPresenter extends Presenter
{
    protected function configure(): void
    {
        $this
            ->only(['email', 'name'])
            ->compute('name', fn (UserDto $dto) => strtoupper($dto->name));
    }
}
