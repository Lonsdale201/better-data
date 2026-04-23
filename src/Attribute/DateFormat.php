<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Overrides the format used when writing a DateTimeInterface property
 * back to a sink (post field, user field, term field, option, meta,
 * row column).
 *
 * Without this attribute, sinks fall back to their built-in defaults:
 *  - Post / User / Term system date fields → MySQL `Y-m-d H:i:s`
 *    (with UTC conversion for `*_gmt` fields)
 *  - Meta, option, row column values           → ISO 8601 (ATOM)
 *
 * Examples:
 *
 *     #[DateFormat('Y-m-d')]
 *     public DateTimeImmutable $deliveryDate;
 *
 *     // Unix timestamp (integer-cast-friendly string):
 *     #[DateFormat('U')]
 *     public DateTimeImmutable $scheduledAt;
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class DateFormat
{
    public function __construct(public string $format)
    {
    }
}
