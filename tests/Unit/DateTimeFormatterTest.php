<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Presenter\Formatter\DateTimeFormatter;
use BetterData\Presenter\PresentationContext;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DateTimeFormatterTest extends TestCase
{
    public function testFormatsInProvidedTimezone(): void
    {
        $ctx = PresentationContext::none()->withTimezone('Europe/Budapest');
        $utc = new DateTimeImmutable('2026-07-01 12:00:00', new DateTimeZone('UTC'));

        $result = (new DateTimeFormatter($ctx))->format($utc, 'Y-m-d H:i');

        // 12:00 UTC in July (CEST) = 14:00 Europe/Budapest
        self::assertSame('2026-07-01 14:00', $result);
    }

    public function testFallsBackToSourceTimezoneWhenContextHasNone(): void
    {
        $ctx = PresentationContext::none();
        $local = new DateTimeImmutable('2026-03-10 09:30:00', new DateTimeZone('America/New_York'));

        self::assertSame('2026-03-10 09:30', (new DateTimeFormatter($ctx))->format($local, 'Y-m-d H:i'));
    }

    public function testCustomFormatStrings(): void
    {
        $ctx = PresentationContext::none();
        $value = new DateTimeImmutable('2026-04-15T10:30:00+00:00');

        $formatter = new DateTimeFormatter($ctx);

        self::assertSame('2026-04-15', $formatter->format($value, 'Y-m-d'));
        self::assertSame('10:30:00', $formatter->format($value, 'H:i:s'));
        self::assertSame((string) $value->getTimestamp(), $formatter->format($value, 'U'));
    }
}
