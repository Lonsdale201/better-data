<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Attribute\PostField;
use BetterData\Attribute\TermField;
use BetterData\Attribute\UserField;
use BetterData\Internal\AttributeDrivenHydrator;
use BetterData\Tests\Fixtures\PostBackedDto;
use BetterData\Tests\Fixtures\TermBackedDto;
use BetterData\Tests\Fixtures\UserBackedDto;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AttributeDrivenHydratorTest extends TestCase
{
    private const POST_FIELDS = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content',
        'post_title', 'post_excerpt', 'post_status', 'comment_status',
        'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
        'post_modified', 'post_modified_gmt', 'post_content_filtered',
        'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type',
        'comment_count',
    ];

    private const USER_FIELDS = [
        'ID', 'user_login', 'user_pass', 'user_nicename', 'user_email',
        'user_url', 'user_registered', 'user_activation_key', 'user_status',
        'display_name',
    ];

    private const TERM_FIELDS = [
        'term_id', 'name', 'slug', 'term_group', 'term_taxonomy_id',
        'taxonomy', 'description', 'parent', 'count',
    ];

    public function testPostShape(): void
    {
        $meta = ['_price' => '199.95', '_stock' => '7', '_description' => 'A widget.'];

        $dto = AttributeDrivenHydrator::hydrate(
            PostBackedDto::class,
            ['ID' => 42, 'post_title' => 'Widget', 'post_status' => 'publish', 'post_date_gmt' => '2026-01-15 10:00:00'],
            self::POST_FIELDS,
            PostField::class,
            static fn (string $key): mixed => $meta[$key] ?? null,
            ['id' => 'ID'],
        );

        self::assertSame(42, $dto->id);
        self::assertSame('Widget', $dto->post_title);
        self::assertEquals(new DateTimeImmutable('2026-01-15 10:00:00'), $dto->publishedAt);
        self::assertSame(199.95, $dto->price);
    }

    public function testUserShape(): void
    {
        $meta = ['billing_city' => 'Budapest', 'loyalty_points' => '250'];

        $dto = AttributeDrivenHydrator::hydrate(
            UserBackedDto::class,
            ['ID' => 5, 'user_login' => 'jane', 'user_email' => 'j@example.com'],
            self::USER_FIELDS,
            UserField::class,
            static fn (string $key): mixed => $meta[$key] ?? null,
            ['id' => 'ID'],
        );

        self::assertSame(5, $dto->id);
        self::assertSame('jane', $dto->login);
        self::assertSame('j@example.com', $dto->email);
        self::assertSame('Budapest', $dto->billingCity);
        self::assertSame(250, $dto->loyaltyPoints);
    }

    public function testUserMissingMetaYieldsDefaultsOrNull(): void
    {
        $dto = AttributeDrivenHydrator::hydrate(
            UserBackedDto::class,
            ['ID' => 1, 'user_login' => 'x', 'user_email' => 'x@y.z'],
            self::USER_FIELDS,
            UserField::class,
            static fn (string $key): mixed => null,
            ['id' => 'ID'],
        );

        self::assertNull($dto->billingCity);
        self::assertSame(0, $dto->loyaltyPoints);
    }

    public function testFieldTimezoneAppliedToDateTimeSystemField(): void
    {
        // post_date_gmt → UTC, post_date → site tz
        $dto = AttributeDrivenHydrator::hydrate(
            PostBackedDto::class,
            [
                'ID' => 1,
                'post_title' => 'T',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-01-15 10:00:00', // no tz offset in the string
            ],
            self::POST_FIELDS,
            PostField::class,
            static fn (string $key): mixed => match ($key) {
                '_price' => '9.99',
                '_stock' => '1',
                default => null,
            },
            ['id' => 'ID'],
            ['post_date_gmt' => 'UTC'],
        );

        self::assertSame('UTC', $dto->publishedAt->getTimezone()->getName());
        self::assertSame('2026-01-15 10:00:00', $dto->publishedAt->format('Y-m-d H:i:s'));
    }

    public function testEmptyStringMetaPreservedWhenKeyExists(): void
    {
        $dto = AttributeDrivenHydrator::hydrate(
            UserBackedDto::class,
            ['ID' => 7, 'user_login' => 'x', 'user_email' => 'x@y.z'],
            self::USER_FIELDS,
            UserField::class,
            static fn (string $key): mixed => $key === 'billing_city' ? '' : null,
            ['id' => 'ID'],
        );

        self::assertSame('', $dto->billingCity, 'empty string stored in meta must round-trip, not fall back to default');
        self::assertSame(0, $dto->loyaltyPoints);
    }

    public function testTermShape(): void
    {
        $meta = ['color' => '#ff00aa'];

        $dto = AttributeDrivenHydrator::hydrate(
            TermBackedDto::class,
            ['term_id' => 9, 'name' => 'Featured', 'slug' => 'featured', 'taxonomy' => 'category'],
            self::TERM_FIELDS,
            TermField::class,
            static fn (string $key): mixed => $meta[$key] ?? null,
            ['id' => 'term_id'],
        );

        self::assertSame(9, $dto->id);
        self::assertSame('Featured', $dto->name);
        self::assertSame('featured', $dto->slug);
        self::assertSame('category', $dto->taxonomy);
        self::assertSame('#ff00aa', $dto->color);
    }

    public function testTermIdAliasIsDistinctFromPostId(): void
    {
        $dto = AttributeDrivenHydrator::hydrate(
            TermBackedDto::class,
            ['term_id' => 123, 'name' => 'A', 'slug' => 'a', 'taxonomy' => 'tag'],
            self::TERM_FIELDS,
            TermField::class,
            static fn (string $key): mixed => null,
            ['id' => 'term_id'],
        );

        self::assertSame(123, $dto->id, 'id property should map to term_id for terms');
    }
}
