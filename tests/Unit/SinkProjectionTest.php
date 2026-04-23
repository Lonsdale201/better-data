<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Attribute\PostField;
use BetterData\Attribute\UserField;
use BetterData\Internal\SinkProjection;
use BetterData\Tests\Fixtures\PostBackedDto;
use BetterData\Tests\Fixtures\UserBackedDto;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SinkProjectionTest extends TestCase
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

    public function testProjectsPostSystemAndMetaBuckets(): void
    {
        $dto = PostBackedDto::fromArray([
            'id' => 42,
            'post_title' => 'Widget',
            'post_status' => 'publish',
            'publishedAt' => '2026-01-15T10:00:00+00:00',
            'price' => 199.95,
            'stock' => 7,
            'description' => 'A widget.',
        ]);

        $projection = SinkProjection::project(
            $dto,
            PostField::class,
            self::POST_FIELDS,
            propertyAliases: ['id' => 'ID'],
            gmtSystemFields: ['post_date_gmt', 'post_modified_gmt'],
        );

        self::assertSame(42, $projection['system']['ID']);
        self::assertSame('Widget', $projection['system']['post_title']);
        self::assertSame('publish', $projection['system']['post_status']);
        self::assertSame('2026-01-15 10:00:00', $projection['system']['post_date_gmt']);
        self::assertSame(199.95, $projection['meta']['_price']);
        self::assertSame(7, $projection['meta']['_stock']);
        self::assertSame('A widget.', $projection['meta']['_description']);
        self::assertSame([], $projection['metaToDelete']);
    }

    public function testNullMetaGoesToDeleteList(): void
    {
        $dto = PostBackedDto::fromArray([
            'id' => 1,
            'post_title' => 't',
            'post_status' => 'draft',
            'publishedAt' => '2026-01-01T00:00:00+00:00',
            'price' => 10,
            'stock' => 0,
            // description intentionally omitted → nullable, will be null
        ]);

        $projection = SinkProjection::project(
            $dto,
            PostField::class,
            self::POST_FIELDS,
            propertyAliases: ['id' => 'ID'],
        );

        self::assertArrayNotHasKey('_description', $projection['meta']);
        self::assertContains('_description', $projection['metaToDelete']);
    }

    public function testOnlyWhitelistRestrictsWriteSet(): void
    {
        $dto = PostBackedDto::fromArray([
            'id' => 1,
            'post_title' => 't',
            'post_status' => 'publish',
            'publishedAt' => '2026-01-01T00:00:00+00:00',
            'price' => 100.0,
            'stock' => 5,
        ]);

        $projection = SinkProjection::project(
            $dto,
            PostField::class,
            self::POST_FIELDS,
            propertyAliases: ['id' => 'ID'],
            only: ['price', 'stock'],
        );

        self::assertSame([], $projection['system']);
        self::assertSame([
            '_price' => 100.0,
            '_stock' => 5,
        ], $projection['meta']);
        self::assertSame([], $projection['metaToDelete']);
    }

    public function testExcludeSystemFieldsNeverAppear(): void
    {
        $dto = UserBackedDto::fromArray([
            'id' => 9,
            'login' => 'alice',
            'email' => 'a@b.co',
        ]);

        $projection = SinkProjection::project(
            $dto,
            UserField::class,
            self::USER_FIELDS,
            propertyAliases: ['id' => 'ID'],
            excludeSystemFields: ['user_pass', 'user_activation_key'],
        );

        self::assertArrayNotHasKey('user_pass', $projection['system']);
        self::assertArrayNotHasKey('user_activation_key', $projection['system']);
        self::assertSame('alice', $projection['system']['user_login']);
        self::assertSame('a@b.co', $projection['system']['user_email']);
    }

    public function testGmtFieldConvertsToUtcBeforeFormatting(): void
    {
        $localTz = new \DateTimeZone('Europe/Budapest');
        $dto = PostBackedDto::fromArray([
            'id' => 1,
            'post_title' => 't',
            'post_status' => 'publish',
            'publishedAt' => (new DateTimeImmutable('2026-07-01 14:30:00', $localTz))->format(\DateTimeInterface::ATOM),
            'price' => 1,
            'stock' => 1,
        ]);

        $projection = SinkProjection::project(
            $dto,
            PostField::class,
            self::POST_FIELDS,
            propertyAliases: ['id' => 'ID'],
            gmtSystemFields: ['post_date_gmt', 'post_modified_gmt'],
        );

        // 14:30 Europe/Budapest (CEST, UTC+2) → 12:30 UTC
        self::assertSame('2026-07-01 12:30:00', $projection['system']['post_date_gmt']);
    }

    public function testSystemFieldNotInKnownListIsSkipped(): void
    {
        // UserBackedDto uses user_login etc. Try it with the POST_FIELDS config
        // where those are unknown → nothing should land in system bucket.
        $dto = UserBackedDto::fromArray([
            'id' => 1,
            'login' => 'x',
            'email' => 'x@y.z',
        ]);

        $projection = SinkProjection::project(
            $dto,
            PostField::class, // mismatched on purpose
            self::POST_FIELDS,
            propertyAliases: ['id' => 'ID'],
        );

        self::assertArrayHasKey('ID', $projection['system']); // id alias still resolves
        self::assertArrayNotHasKey('user_login', $projection['system']);
        self::assertArrayNotHasKey('user_email', $projection['system']);
    }
}
