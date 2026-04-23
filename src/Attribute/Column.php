<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Maps a DataObject property to a custom table column name.
 *
 * Only needed when the property name differs from the column:
 *
 *     #[Column('user_id')]
 *     public int $userId,
 *
 * If property and column share the same name, the attribute can be
 * omitted — RowSource defaults to property-name = column-name.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    public function __construct(public string $name)
    {
    }
}
