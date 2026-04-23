<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Source\RowSource;
use BetterData\Tests\Fixtures\OrderRowDto;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RowSourceTest extends TestCase
{
    public function testHydrateAppliesColumnAliasesAndCoercesStrings(): void
    {
        $row = [
            'id' => '101',
            'user_id' => '42',
            'order_total' => '199.95',
            'status' => 'paid',
            'created_at' => '2026-03-01 12:34:56',
        ];

        $dto = RowSource::hydrate($row, OrderRowDto::class);

        self::assertSame(101, $dto->id);
        self::assertSame(42, $dto->userId);
        self::assertSame(199.95, $dto->total);
        self::assertSame('paid', $dto->status);
        self::assertEquals(new DateTimeImmutable('2026-03-01 12:34:56'), $dto->createdAt);
    }

    public function testHydrateAcceptsObjectRow(): void
    {
        $row = (object) [
            'id' => '5',
            'user_id' => '10',
            'order_total' => '50',
            'status' => 'pending',
            'created_at' => '2026-01-01 00:00:00',
        ];

        $dto = RowSource::hydrate($row, OrderRowDto::class);

        self::assertSame(5, $dto->id);
        self::assertSame(10, $dto->userId);
    }

    public function testHydrateMany(): void
    {
        $rows = [
            ['id' => '1', 'user_id' => '1', 'order_total' => '10', 'status' => 'paid', 'created_at' => '2026-01-01 00:00:00'],
            ['id' => '2', 'user_id' => '2', 'order_total' => '20', 'status' => 'paid', 'created_at' => '2026-01-02 00:00:00'],
        ];

        $dtos = RowSource::hydrateMany($rows, OrderRowDto::class);

        self::assertCount(2, $dtos);
        self::assertSame(1, $dtos[0]->id);
        self::assertSame(2, $dtos[1]->id);
    }
}
