<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Sink\RowSink;
use BetterData\Tests\Fixtures\OrderRowDto;
use BetterData\Tests\Fixtures\ScheduledJobDto;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class RowSinkTest extends TestCase
{
    public function testToArrayRespectsColumnAliases(): void
    {
        $dto = OrderRowDto::fromArray([
            'id' => 101,
            'userId' => 42,
            'total' => 199.95,
            'status' => 'paid',
            'createdAt' => '2026-03-01T12:34:56+00:00',
        ]);

        $row = RowSink::toArray($dto);

        self::assertArrayHasKey('user_id', $row);
        self::assertSame(42, $row['user_id']);
        self::assertArrayHasKey('order_total', $row);
        self::assertSame(199.95, $row['order_total']);
        self::assertArrayNotHasKey('userId', $row);
    }

    public function testToArrayFormatsDateTimeAsMysqlUtcByDefault(): void
    {
        $local = new DateTimeImmutable('2026-07-01 14:30:00', new DateTimeZone('Europe/Budapest'));

        $dto = OrderRowDto::fromArray([
            'id' => 1,
            'userId' => 1,
            'total' => 1,
            'status' => 'pending',
            'createdAt' => $local,
        ]);

        self::assertSame('2026-07-01 12:30:00', RowSink::toArray($dto)['created_at']);
    }

    public function testDateFormatAttributeOverridesDefault(): void
    {
        $dto = ScheduledJobDto::fromArray([
            'id' => 1,
            'name' => 'cleanup',
            'scheduledAt' => '2026-05-15T10:00:00+00:00',
            'deliveryDate' => '2026-05-20T00:00:00+00:00',
            'epoch' => '2026-05-15T10:00:00+00:00',
        ]);

        $row = RowSink::toArray($dto);

        self::assertSame('2026-05-15 10:00:00', $row['scheduled_at']);
        self::assertSame('2026-05-20', $row['delivery_date']);
        self::assertSame(
            (string) (new DateTimeImmutable('2026-05-15T10:00:00+00:00'))->getTimestamp(),
            $row['epoch'],
        );
    }

    public function testOnlyWhitelist(): void
    {
        $dto = OrderRowDto::fromArray([
            'id' => 5,
            'userId' => 10,
            'total' => 50.0,
            'status' => 'paid',
            'createdAt' => '2026-01-01T00:00:00+00:00',
        ]);

        $row = RowSink::toArray($dto, only: ['status', 'total']);

        self::assertSame(['order_total' => 50.0, 'status' => 'paid'], $row);
    }
}
