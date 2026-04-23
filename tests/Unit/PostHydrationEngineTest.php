<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Internal\PostHydrationEngine;
use BetterData\Tests\Fixtures\PostBackedDto;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PostHydrationEngineTest extends TestCase
{
    public function testResolvesPostFieldsAndMetaTogether(): void
    {
        $postFields = [
            'ID' => 42,
            'post_title' => 'Widget',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-01-15 10:00:00',
        ];

        $meta = [
            '_price' => '199.95',
            '_stock' => '7',
            '_description' => 'A widget.',
        ];

        $dto = PostHydrationEngine::hydrate(
            PostBackedDto::class,
            $postFields,
            static fn (string $key): mixed => $meta[$key] ?? '',
        );

        self::assertSame(42, $dto->id);
        self::assertSame('Widget', $dto->post_title);
        self::assertSame('publish', $dto->post_status);
        self::assertEquals(new DateTimeImmutable('2026-01-15 10:00:00'), $dto->publishedAt);
        self::assertSame(199.95, $dto->price);
        self::assertSame(7, $dto->stock);
        self::assertSame('A widget.', $dto->description);
    }

    public function testMissingMetaFallsBackToDefaultOrNull(): void
    {
        $postFields = [
            'ID' => 1,
            'post_title' => 'x',
            'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00',
        ];

        $dto = PostHydrationEngine::hydrate(
            PostBackedDto::class,
            $postFields,
            static fn (string $key): mixed => match ($key) {
                '_price' => '10',
                '_stock' => '0',
                default => '',
            },
        );

        self::assertNull($dto->description, 'Missing nullable meta should resolve to null');
    }

    public function testIdPropertyMapsToUppercaseId(): void
    {
        $postFields = [
            'ID' => 777,
            'post_title' => 't',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-02-01 00:00:00',
        ];

        $dto = PostHydrationEngine::hydrate(
            PostBackedDto::class,
            $postFields,
            static fn (string $key): mixed => match ($key) {
                '_price' => '0',
                '_stock' => '0',
                default => '',
            },
        );

        self::assertSame(777, $dto->id);
    }
}
