<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Marks a DataObject property as backed by a WordPress meta entry.
 *
 * Usage:
 *
 *     #[MetaKey('_price')]
 *     public float $price,
 *
 * Applies to post meta, user meta and term meta, depending on the
 * source adapter used at hydration time. The meta key is always
 * explicit — no automatic property-name → meta-key mapping.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MetaKey
{
    public function __construct(public string $key)
    {
    }
}
